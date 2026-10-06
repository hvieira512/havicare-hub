<?php

declare(strict_types=1);

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Device\MessageFanout;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * O stream de um inquilino: tudo o que o MQTT leva da sua empresa e licença. O âmbito sai do
 * token, e os canais que o cliente escolhe só o podem estreitar.
 */
final class StreamController
{
    /** O `raw` fica de fora: é o canal de depuração, 98% dos bytes, e serve-se por dispositivo. */
    private const CHANNELS = ['telemetry', 'events', 'status'];

    /**
     * Quantos frames uma ligação pode ter à espera antes de ser fechada. Aqui não se salta um
     * envio: um espelho de mensagens não tem estado para reler.
     */
    private const QUEUE_LIMIT = 256;

    /** Um comentário periódico, para a ligação não morrer em silêncio num proxy. */
    private const KEEP_ALIVE_SECONDS = 15;

    private int $open = 0;

    /** @var array<string, int> */
    private array $openPerUser = [];

    public function __construct(
        private MessageFanout $messages,
        private JsonResponder $json,
        private int $maxOpen = 200,
        private int $maxOpenPerUser = 5,
    ) {
    }

    /** @param array<string, string> $params */
    public function stream(array $params, ServerRequestInterface $request): Response
    {
        $auth = RequestContext::auth($request);
        if ($auth === null) {
            return $this->json->result(ApiError::unauthorized()->toArray());
        }

        // O teto vem antes de tudo: é a verificação mais barata e é a que protege o processo.
        $user = $auth->username;
        if ($this->open >= $this->maxOpen || ($this->openPerUser[$user] ?? 0) >= $this->maxOpenPerUser) {
            return $this->json->result(ApiError::tooManyStreams()->toArray());
        }

        $company = trim((string)$auth->company);
        $licenseId = (int)$auth->licenseId;

        // Um administrador pode nomear o inquilino, porque o fanout é por âmbito e sem wildcard; para
        // o `license_client` o âmbito sai só do token.
        if ($auth->isAdmin()) {
            parse_str((string)$request->getUri()->getQuery(), $params);
            $company = trim((string)($params['company'] ?? ''));
            $licenseId = (int)($params['licenseId'] ?? 0);
        }

        if ($company === '' || $licenseId <= 0) {
            return $this->json->result(ApiError::invalidRequest(
                'This stream requires a license client, or a hub admin naming company and licenseId'
            )->toArray());
        }

        $channels = $this->requestedChannels($request);
        if (is_string($channels)) {
            return $this->json->result(ApiError::invalidRequest($channels)->toArray());
        }

        return $this->serve($company, $licenseId, $channels, $user);
    }

    /**
     * @param list<string> $channels
     */
    private function serve(string $company, int $licenseId, array $channels, string $user): Response
    {
        $loop = Loop::get();
        $stream = new ThroughStream();

        $this->open++;
        $this->openPerUser[$user] = ($this->openPerUser[$user] ?? 0) + 1;

        /** @var list<string> $queue */
        $queue = [];
        $blocked = false;
        $flushScheduled = false;
        $closed = false;

        $flush = static function () use ($stream, &$queue, &$blocked): void {
            while ($queue !== [] && !$blocked) {
                if (!$stream->isWritable()) {
                    $queue = [];
                    return;
                }

                $blocked = $stream->write(array_shift($queue)) === false;
            }
        };

        $stream->on('drain', static function () use (&$blocked, $flush): void {
            $blocked = false;
            $flush();
        });

        $unsubscribes = [];
        foreach ($channels as $channel) {
            $unsubscribes[] = $this->messages->subscribe(
                MessageFanout::scope($company, $licenseId, $channel),
                // Chamado de dentro do `publish()`, no caminho da ingestão: aqui só se acumula e agenda, e a
                // escrita nos sockets fica para o tique seguinte do loop.
                static function (
                    string $topic,
                    string $json
                ) use (
                    $loop,
                    $company,
                    $licenseId,
                    $channel,
                    $stream,
                    &$queue,
                    &$flushScheduled,
                    $flush
                ): void {
                    if ($json === '' || !$stream->isWritable()) {
                        return;
                    }

                    if (count($queue) >= self::QUEUE_LIMIT) {
                        // Transbordou: fecha-se a dizer porquê, em vez de descartar frames em
                        // silêncio.
                        $stream->write("event: overflow\ndata: {\"reason\":\"client_too_slow\"}\n\n");
                        $stream->end();
                        return;
                    }

                    $queue[] = self::frame($company, $licenseId, $channel, $topic, $json);

                    if ($flushScheduled) {
                        return;
                    }
                    $flushScheduled = true;
                    $loop->futureTick(static function () use (&$flushScheduled, $flush): void {
                        $flushScheduled = false;
                        $flush();
                    });
                }
            );
        }

        $keepAlive = $loop->addPeriodicTimer(
            self::KEEP_ALIVE_SECONDS,
            static function () use ($stream, &$blocked): void {
                if ($stream->isWritable() && !$blocked) {
                    $blocked = $stream->write(": keep-alive\n\n") === false;
                }
            }
        );

        $stream->on('close', function () use ($loop, $keepAlive, $unsubscribes, $user, &$closed): void {
            if ($closed) {
                return;
            }
            $closed = true;

            $loop->cancelTimer($keepAlive);
            foreach ($unsubscribes as $unsubscribe) {
                $unsubscribe();
            }

            $this->open--;
            $remaining = ($this->openPerUser[$user] ?? 1) - 1;
            if ($remaining > 0) {
                $this->openPerUser[$user] = $remaining;
            } else {
                unset($this->openPerUser[$user]);
            }
        });

        return new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $stream);
    }

    /**
     * Os canais pedidos, ou a mensagem de erro quando algum não é servido.
     *
     * @return list<string>|string
     */
    private function requestedChannels(ServerRequestInterface $request): array|string
    {
        parse_str((string)$request->getUri()->getQuery(), $params);
        $requested = trim((string)($params['channels'] ?? ''));
        if ($requested === '') {
            return self::CHANNELS;
        }

        $channels = [];
        foreach (explode(',', $requested) as $channel) {
            $channel = strtolower(trim($channel));
            if ($channel === '') {
                continue;
            }
            if (!in_array($channel, self::CHANNELS, true)) {
                return "channel '{$channel}' is not served; channels must be a subset of "
                    . implode(', ', self::CHANNELS);
            }
            $channels[$channel] = $channel;
        }

        return $channels === [] ? self::CHANNELS : array_values($channels);
    }

    /**
     * O stream não tem tópico, e o envelope devolve a empresa, a licença, o tipo e o dispositivo
     * à volta do `payload`, que entra por concatenação: é a mesma string do fio, byte a byte.
     */
    private static function frame(
        string $company,
        int $licenseId,
        string $channel,
        string $topic,
        string $json
    ): string {
        // O tipo e o dispositivo contam-se do fim: o prefixo da instância pode ser vazio.
        $parts = explode('/', $topic);
        $count = count($parts);

        $envelope = json_encode([
            'topic' => $topic,
            'company' => $company,
            'licenseId' => $licenseId,
            'deviceType' => $parts[$count - 3] ?? '',
            'deviceId' => $parts[$count - 2] ?? '',
            'channel' => $channel,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        // A concatenação obriga o envelope a acabar em `}` com pelo menos um campo lá dentro.
        if ($envelope === false) {
            $envelope = json_encode(['channel' => $channel], JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"channel":""}';
        }

        return "event: {$channel}\ndata: " . substr($envelope, 0, -1) . ',"payload":' . $json . "}\n\n";
    }
}
