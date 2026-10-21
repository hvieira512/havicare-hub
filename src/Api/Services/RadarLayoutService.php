<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Command\Configuration\Payload\QinglanstPayloadBuilder;
use Hub\Domain\DeviceMetadata;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * A planta de um radar: ler a que está guardada, e ir buscar outra só quando alguém a pede,
 * sem relógio nenhum atrás.
 */
class RadarLayoutService
{
    /** O motivo de uma ida à cloud falhada: o login é o único passo que falha a sincronização inteira. */
    public const LOGIN_FAILED = 'login_failed';

    public function __construct(
        private ApiDataAccess $db,
        private RadarLayoutSync $sync,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return ApiError::deviceNotFound()->toArray();
        }

        $layout = $this->db->radarLayouts->findByImei($imei);
        if ($layout === null) {
            // Um radar por sincronizar não é um erro: é o estado em que todos nascem, e o
            // ecrã tem de saber desenhar o vazio em vez de um alarme.
            return ['data' => ['configured' => false, 'room' => null, 'areas' => [], 'fetchedAt' => null, 'maxAreas' => QinglanstPayloadBuilder::MAX_AREAS]];
        }

        return ['data' => [
            'configured' => true,
            'room' => $layout['room'],
            'areas' => $layout['areas'],
            'fetchedAt' => $layout['fetchedAt'],
            'maxAreas' => QinglanstPayloadBuilder::MAX_AREAS,
        ]];
    }

    /**
     * O botão de sincronizar.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function sync(string $imei, ?ApiAuthContext $auth = null): PromiseInterface
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return resolve(ApiError::deviceNotFound()->toArray());
        }

        return $this->sync->syncDevice($imei)->then(function (array $result) use ($imei, $auth): array {
            $refusal = match ($result['error']) {
                'device_is_not_a_radar' => ApiError::deviceIsNotARadar(),
                'license_has_no_radar_credentials' => ApiError::licenseHasNoRadarCredentials(),
                default => null,
            };
            if ($refusal !== null) {
                return $refusal->toArray();
            }

            return ['data' => [
                'synced' => $result['synced'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                // O código do fabricante viaja para quem está a ver: um `777` quer dizer que
                // a cloud não conhece aquele aparelho, e isso não se adivinha de um "falhou".
                'codes' => $result['codes'],
                'error' => $result['error'] === null ? null : self::LOGIN_FAILED,
                'layout' => $this->show($imei, $auth)['data'] ?? null,
            ]];
        });
    }

    /** Um aparelho que não existe e um de outro inquilino são, para quem pergunta, a mesma resposta. */
    private function canAccessDevice(string $imei, ?ApiAuthContext $auth): bool
    {
        $device = $this->db->whitelist->get($imei);
        if ($device === null || $auth === null) {
            return $device !== null;
        }

        return $auth->canAccessTenant(
            DeviceMetadata::normalizeCompany((string)($device['company'] ?? '')),
            DeviceMetadata::normalizeLicenseId($device['license_id'] ?? 0),
        );
    }
}
