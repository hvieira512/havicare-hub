<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst\Cloud;

use Hub\Command\Downlink\PendingDownlink;
use Hub\Command\Downlink\RedisPendingDownlinkQueue;
use Hub\Log\Logger;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Entrega pela cloud da Qinglanst o que está em fila para um radar. Cada escrita lê primeiro o
 * radar, monta os pedidos sobre o que lá está, e fecha-se pela resposta: 200 aplicado, 777 radar
 * desligado (fica em fila), 778 modo que o modelo não aceita.
 */
final class QinglanstCloudDownlinks
{
    private const CODE_OK = 200;
    private const CODE_OFFLINE = 777;
    private const CODE_MODE_NOT_SUPPORTED = 778;

    /** Com o radar desligado, a mesma alteração só volta a sair ao fim disto. */
    private const RESEND_SECONDS = 30;

    /** As mensagens de um radar chegam várias por segundo; a fila lê-se de tanto em tanto. */
    private const CHECK_EVERY_SECONDS = 5;

    /** @var array<string, int> */
    private array $checkedAt = [];

    /** @var array<string, int> */
    private array $sentAt = [];

    /** @var array<string, true> um radar de cada vez: as escritas leem o que a anterior deixou */
    private array $busy = [];

    /** @var array<string, true> o que entrou na fila enquanto o radar estava ocupado */
    private array $again = [];

    /** @var \Closure(string): (array{uid: string, credentials: array<string, string>|null}|null) */
    private \Closure $radar;

    /** @var \Closure(string): PromiseInterface<array<string, mixed>> */
    private \Closure $readRadar;

    /** @var \Closure(string, string, array<string, mixed>): mixed */
    private \Closure $mark;

    /**
     * @param callable(string): (array{uid: string, credentials: array<string, string>|null}|null) $radar
     *     o `uid` na cloud e as credenciais da licença; `null` para o que não é um radar
     * @param callable(string): PromiseInterface<array<string, mixed>> $readRadar lê o radar e guarda o que ele tem
     * @param callable(string, string, array<string, mixed>): mixed $mark o estado de um comando
     */
    public function __construct(
        private readonly RedisPendingDownlinkQueue $queue,
        private readonly QinglanstApiClient $client,
        private readonly QinglanstCloudCommands $commands,
        callable $radar,
        callable $readRadar,
        callable $mark,
    ) {
        $this->radar = $radar(...);
        $this->readRadar = $readRadar(...);
        $this->mark = $mark(...);
    }

    /**
     * @param bool $now `true` quando algo acabou de entrar na fila; uma mensagem do radar só
     *     olha para a fila de tanto em tanto
     * @return PromiseInterface<null>
     */
    public function dispatchPending(string $imei, bool $now = false): PromiseInterface
    {
        $time = time();
        if (isset($this->busy[$imei])) {
            if ($now) {
                $this->again[$imei] = true;
            }
            return resolve(null);
        }
        if (!$now && $time - ($this->checkedAt[$imei] ?? 0) < self::CHECK_EVERY_SECONDS) {
            return resolve(null);
        }
        $this->checkedAt[$imei] = $time;

        $radar = ($this->radar)($imei);
        if ($radar === null) {
            return resolve(null);
        }

        $due = array_values(array_filter(
            $this->queue->pendingFor($imei),
            fn (PendingDownlink $downlink): bool => $time - ($this->sentAt[$downlink->dedupeKey] ?? 0) >= self::RESEND_SECONDS,
        ));
        if ($due === []) {
            return resolve(null);
        }

        $this->busy[$imei] = true;
        $chain = resolve(null);
        foreach ($due as $downlink) {
            $chain = $chain->then(fn (): PromiseInterface => $this->deliver($imei, $radar, $downlink));
        }

        return $chain->then(
            function () use ($imei): null {
                unset($this->busy[$imei]);
                if (isset($this->again[$imei])) {
                    unset($this->again[$imei]);
                    $this->dispatchPending($imei, now: true);
                }

                return null;
            },
            function (mixed $error) use ($imei): null {
                unset($this->busy[$imei]);
                Logger::channel('hub')->error('Qinglanst cloud delivery failed for ' . $imei . ': '
                    . ($error instanceof \Throwable ? $error->getMessage() : (string)$error));

                return null;
            },
        );
    }

    /**
     * @param array{uid: string, credentials: array<string, string>|null} $radar
     * @return PromiseInterface<null>
     */
    private function deliver(string $imei, array $radar, PendingDownlink $downlink): PromiseInterface
    {
        $operation = (string)($downlink->command['command'] ?? $downlink->bytes);
        $payload = is_array($downlink->command['payload'] ?? null) ? $downlink->command['payload'] : [];
        $this->sentAt[$downlink->dedupeKey] = time();
        $this->mark($imei, $downlink, ['status' => 'waiting', 'sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'announceSent' => true]);

        if ($operation === 'read_device_state') {
            // Sem código nenhum, a cloud não respondeu: o motivo é o que a leitura contou.
            return ($this->readRadar)($imei)->then(fn (array $tally) => self::readCode($tally) === 0
                ? $this->fail($imei, $downlink, 'cloud_unreachable', (string)($tally['error'] ?? ''))
                : $this->close($imei, $downlink, self::readCode($tally), ''));
        }

        $credentials = $radar['credentials'];
        if ($credentials === null) {
            return resolve($this->fail($imei, $downlink, 'license_has_no_radar_credentials'));
        }

        return $this->client->login($credentials)->then(
            fn (array $token): PromiseInterface => $this->client
                ->deviceProp($credentials, $token, $radar['uid'])
                ->then(function (array $response) use ($imei, $downlink, $operation, $payload, $radar, $credentials, $token) {
                    $code = (int)($response['code'] ?? 0);
                    if ($code !== self::CODE_OK || !is_array($response['data'] ?? null)) {
                        return $this->close($imei, $downlink, $code, (string)($response['msg'] ?? ''));
                    }

                    try {
                        $requests = $this->commands->requests($operation, $payload, $response['data'], $radar['uid']);
                    } catch (\InvalidArgumentException $error) {
                        return $this->fail($imei, $downlink, 'invalid_config', $error->getMessage());
                    }

                    return $this->write($credentials, $token, $requests)->then(
                        fn (array $answer) => $this->close($imei, $downlink, $answer['code'], $answer['msg']),
                    );
                }),
        )->then(null, fn (mixed $error) => $this->fail(
            $imei,
            $downlink,
            'cloud_unreachable',
            $error instanceof \Throwable ? $error->getMessage() : (string)$error,
        ));
    }

    /**
     * Os pedidos por ordem, parando no primeiro que não seja 200.
     *
     * @param array<string, string> $credentials
     * @param array{access_token: string, token_type: string} $token
     * @param list<array{path: string, body: array<string, mixed>}> $requests
     * @return PromiseInterface<array{code: int, msg: string}>
     */
    private function write(array $credentials, array $token, array $requests): PromiseInterface
    {
        $chain = resolve(['code' => self::CODE_OK, 'msg' => '']);
        foreach ($requests as $request) {
            $chain = $chain->then(fn (array $previous): PromiseInterface => $previous['code'] !== self::CODE_OK
                ? resolve($previous)
                : $this->client->post($credentials, $token, $request['path'], $request['body'])->then(
                    static fn (array $response): array => [
                        'code' => (int)($response['code'] ?? 0),
                        'msg' => (string)($response['msg'] ?? ''),
                    ],
                ));
        }

        return $chain;
    }

    private function close(string $imei, PendingDownlink $downlink, int $code, string $message): null
    {
        if ($code === self::CODE_OFFLINE) {
            // Fica em fila: sai outra vez na próxima mensagem do radar, quando ele voltar.
            $this->mark($imei, $downlink, ['status' => 'queued', 'error' => 'device_offline']);
            return null;
        }

        if ($code !== self::CODE_OK) {
            return $this->fail(
                $imei,
                $downlink,
                $code === self::CODE_MODE_NOT_SUPPORTED ? 'mode_not_supported' : 'cloud_rejected',
                $message,
            );
        }

        $this->queue->remove($downlink);
        unset($this->sentAt[$downlink->dedupeKey]);
        $this->mark($imei, $downlink, ['status' => 'acked', 'ackedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        Logger::channel('hub')->info("Qinglanst {$downlink->bytes} applied to {$imei} through the cloud");
        if (($downlink->command['command'] ?? '') !== 'read_device_state') {
            ($this->readRadar)($imei);
        }

        return null;
    }

    private function fail(string $imei, PendingDownlink $downlink, string $error, string $message = ''): null
    {
        $this->queue->remove($downlink);
        unset($this->sentAt[$downlink->dedupeKey]);
        $this->mark($imei, $downlink, array_filter(
            ['status' => 'failed', 'error' => $error, 'errorMessage' => $message],
            static fn (string $value): bool => $value !== '',
        ));
        Logger::channel('hub')->warning("Qinglanst {$downlink->bytes} not applied to {$imei}: {$error} {$message}");

        return null;
    }

    /** @param array<string, mixed> $fields */
    private function mark(string $imei, PendingDownlink $downlink, array $fields): void
    {
        $id = (string)($downlink->command['operationId'] ?? $downlink->command['id'] ?? '');
        if ($id !== '') {
            ($this->mark)($imei, $id, $fields);
        }
    }

    /**
     * O código da leitura que o `RadarLayoutSync` contou: 200 se o radar respondeu.
     *
     * @param array<string, mixed> $tally
     */
    private static function readCode(array $tally): int
    {
        $codes = is_array($tally['codes'] ?? null) ? $tally['codes'] : [];
        if (isset($codes[(string)self::CODE_OK])) {
            return self::CODE_OK;
        }

        return (int)(array_key_first($codes) ?? 0);
    }
}
