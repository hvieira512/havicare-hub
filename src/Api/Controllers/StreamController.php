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
 * O stream de um inquilino: tudo o que o MQTT leva da sua empresa e licença, e nada fora dela.
 *
 * O âmbito nunca vem do pedido -- é composto a partir do token, e por isso não existe
 * parâmetro que o alargue. O que o cliente escolhe são os canais, e essa escolha só o pode
 * estreitar.
 */
final class StreamController
{
    /**
     * O `raw` fica de fora de propósito. É o canal de depuração -- 98% dos bytes publicados,
     * com o conteúdo aninhado sob uma chave chamada `debug` -- e uma mangueira de inquilino é
     * o pior sítio para o servir. Para isso o lugar é o dispositivo em concreto.
     */
    private const CHANNELS = ['telemetry', 'events', 'status'];

    /**
     * Quantos frames uma ligação pode ter à espera antes de ser fechada.
     *
     * Ao contrário do stream de um dispositivo, aqui não se pode saltar um envio: aquele relê
     * o estado autoritativo e não perde nada ao saltar, e um espelho de mensagens não tem
     * estado para reler. Perder um `event` em silêncio é pior do que uma religação -- quem
     * religa volta a listar e reencontra o estado; quem não sabe que perdeu um alarme fica a
     * mostrar o mundo errado com confiança.
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
        if ($company === '' || $licenseId <= 0) {
            // Um administrador não tem inquilino, e o âmbito dele seria o sistema inteiro --
            // que é justamente o caso patológico deste stream. Quem quer isto é um
            // `license_client`.
            return $this->json->result(ApiError::invalidRequest(
                'This stream requires a license client; a hub admin has no tenant scope'
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
                // A dispersão é chamada de dentro do `publish()`, que corre no caminho da
                // ingestão. Aqui só se acumula e se agenda: o caminho do dispositivo paga um
                // append, e as escritas nos sockets ficam para o tique seguinte do loop.
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
     * No MQTT a empresa, a licença, o tipo e o dispositivo vivem no tópico, e o payload leva
     * apenas o `device.id`. Um stream não tem tópico, e por isso o envelope devolve essa
     * informação -- envolvendo em vez de misturar.
     *
     * O `payload` entra por concatenação, e não por descodificar e voltar a codificar: é a
     * mesma string que vai para o fio, byte a byte, e quem já tem código escrito contra o MQTT
     * reutiliza a desserialização que tem.
     */
    private static function frame(
        string $company,
        int $licenseId,
        string $channel,
        string $topic,
        string $json
    ): string {
        // O tipo e o dispositivo contam-se do fim, e não do princípio: o prefixo da instância
        // pode ser vazio, e nesse caso os índices contados da frente andavam todos um atrás.
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

        if ($envelope === false) {
            $envelope = '{}';
        }

        return "event: {$channel}\ndata: " . substr($envelope, 0, -1) . ',"payload":' . $json . "}\n\n";
    }
}
