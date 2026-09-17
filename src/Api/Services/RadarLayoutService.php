<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * A planta de um radar: ler a que está guardada, e ir buscar outra quando alguém pede.
 *
 * Ir buscar acontece só a pedido. Não há relógio nenhum atrás disto: a planta de uma divisão
 * muda quando alguém lá vai mexer no aparelho, e quem carrega no botão é que sabe quando foi.
 */
class RadarLayoutService
{
    /** Recusas do pedido, e não avarias: o cliente corrige-as, e por isso saem como 400. */
    private const REFUSALS = ['device_is_not_a_radar', 'license_has_no_radar_credentials'];

    public function __construct(
        private ApiDataAccess $db,
        private RadarLayoutSync $sync,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(string $imei): array
    {
        $device = $this->db->whitelist->get($imei);
        if ($device === null) {
            return ApiError::deviceNotFound()->toArray();
        }

        $layout = $this->db->radarLayouts->findByImei($imei);
        if ($layout === null) {
            // Um radar por sincronizar não é um erro: é o estado em que todos nascem, e o
            // ecrã tem de saber desenhar o vazio em vez de um alarme.
            return ['data' => ['configured' => false, 'room' => null, 'areas' => [], 'fetchedAt' => null]];
        }

        return ['data' => [
            'configured' => true,
            'room' => $layout['room'],
            'areas' => $layout['areas'],
            'fetchedAt' => $layout['fetched_at'],
        ]];
    }

    /**
     * O botão de sincronizar.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function sync(string $imei): PromiseInterface
    {
        if ($this->db->whitelist->get($imei) === null) {
            return resolve(ApiError::deviceNotFound()->toArray());
        }

        return $this->sync->syncDevice($imei)->then(function (array $result) use ($imei): array {
            if (in_array($result['error'] ?? '', self::REFUSALS, true)) {
                return ApiError::invalidRequest((string)$result['error'])->toArray();
            }

            return ['data' => [
                'synced' => $result['synced'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                // O código do fabricante viaja para quem está a ver: um `777` quer dizer que
                // a cloud não conhece aquele aparelho, e isso não se adivinha de um "falhou".
                'codes' => $result['codes'],
                'error' => $result['error'],
                'layout' => $this->show($imei)['data'] ?? null,
            ]];
        });
    }
}
