<?php

namespace Hub\Ingress\Http\Qinglanst;

use Hub\Api\Repository\RadarApiCredentialsRepository;
use Hub\Api\Repository\RadarLayoutRepository;
use Hub\Api\Repository\WhitelistRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Traz da cloud do fabricante a planta de cada radar e guarda-a.
 *
 * Corre por licença, com um login por licença e não um por radar: são quinze radares numa
 * delas, e o fabricante não distingue quinze logins legítimos de uma tentativa de força bruta.
 *
 * Os radares de uma licença são percorridos por ordem e não em paralelo. É de propósito: a
 * cloud é de terceiros, a sincronização não tem pressa nenhuma, e quinze pedidos ao mesmo
 * tempo são a forma mais rápida de alguém nos fechar a porta.
 */
final class RadarLayoutSync
{
    /** O que o fabricante devolve quando correu bem. Tudo o resto é resposta e não avaria. */
    private const CODE_OK = 200;

    /** @var callable(): string */
    private $now;

    public function __construct(
        private QinglanstApiClient $client,
        private LayoutParser $parser,
        private RadarLayoutRepository $layouts,
        private RadarApiCredentialsRepository $credentials,
        private WhitelistRepository $whitelist,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn(): string => gmdate('Y-m-d H:i:s');
    }

    /**
     * A planta de um radar, a pedido.
     *
     * É o único caminho por onde a sincronização acontece: não há relógio nenhum atrás dela.
     * Quem carrega no botão é que decide quando se vai falar com a cloud do fabricante.
     *
     * @return PromiseInterface<array{synced: int, skipped: int, failed: int, codes: array<string, int>, error: string|null}>
     */
    public function syncDevice(string $imei): PromiseInterface
    {
        $device = $this->whitelist->get($imei);
        if ($device === null || ($device['device_type'] ?? '') !== 'radar') {
            return resolve($this->tally(failed: 1, error: 'device_is_not_a_radar'));
        }

        $credentials = $this->credentials->findByLicenseId((int)$device['license_id']);
        if ($credentials === null) {
            return resolve($this->tally(failed: 1, error: 'license_has_no_radar_credentials'));
        }

        // O fabricante indexa por `uid`, o hub por IMEI canónico, e as duas colunas não têm de
        // coincidir. É o `device_id` que vale lá fora.
        $uid = trim((string)($device['device_id'] ?? ''));

        return $this->syncLicense($credentials, [[
            'imei' => $imei,
            'uid' => $uid !== '' ? $uid : $imei,
        ]]);
    }

    /**
     * @param array<string, string> $credentials
     * @param list<array{imei: string, uid: string}> $radars
     * @return PromiseInterface<array{synced: int, skipped: int, failed: int, codes: array<string, int>, error: string|null}>
     */
    public function syncLicense(array $credentials, array $radars): PromiseInterface
    {
        if ($radars === []) {
            return resolve($this->tally());
        }

        return $this->client->login($credentials)->then(
            fn(array $token): PromiseInterface => $this->syncRadars($credentials, $token, $radars),
            // Um login que falha não são quinze falhas de radar com a mesma causa: a licença
            // inteira fica por sincronizar, e o motivo é um só.
            fn(mixed $error): PromiseInterface => resolve($this->tally(
                failed: count($radars),
                error: $error instanceof \Throwable ? $error->getMessage() : (string)$error,
            )),
        );
    }

    /**
     * @param array<string, string> $credentials
     * @param array{access_token: string, token_type: string} $token
     * @param list<array{imei: string, uid: string}> $radars
     * @return PromiseInterface<array<string, mixed>>
     */
    private function syncRadars(array $credentials, array $token, array $radars): PromiseInterface
    {
        $chain = resolve($this->tally());

        foreach ($radars as $radar) {
            $chain = $chain->then(fn(array $tally): PromiseInterface => $this->client
                ->deviceProp($credentials, $token, $radar['uid'])
                ->then(
                    fn(array $response): array => $this->absorb($tally, $radar['imei'], $response),
                    // Um radar que rebenta não leva os seguintes atrás: a resposta dele conta
                    // como falha e a licença é percorrida até ao fim.
                    static fn(mixed $error): array => [...$tally, 'failed' => $tally['failed'] + 1],
                ));
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $tally
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function absorb(array $tally, string $imei, array $response): array
    {
        $code = (string)($response['code'] ?? '?');
        $tally['codes'][$code] = ($tally['codes'][$code] ?? 0) + 1;

        $data = $response['data'] ?? null;
        $layout = (int)($response['code'] ?? 0) === self::CODE_OK && is_array($data)
            ? $this->parser->parse($data)
            : null;

        // Nem um `777` nem uma resposta sem sala apagam o que está guardado: o aparelho pode
        // ter saído da conta ou estar mesmo em baixo, e a planta continua a valer até alguém
        // declarar outra.
        if ($layout === null) {
            return [...$tally, 'skipped' => $tally['skipped'] + 1];
        }

        $this->layouts->store(
            $imei,
            $layout,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ($this->now)(),
        );

        return [...$tally, 'synced' => $tally['synced'] + 1];
    }

    /**
     * @param array<string, int> $codes
     * @return array<string, mixed>
     */
    private function tally(int $synced = 0, int $skipped = 0, int $failed = 0, array $codes = [], ?string $error = null): array
    {
        return ['synced' => $synced, 'skipped' => $skipped, 'failed' => $failed, 'codes' => $codes, 'error' => $error];
    }
}
