<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceReportStore;
use Hub\Device\HubMqttBridge;
use Hub\Device\RawPayload;
use Hub\Support\Throttle;

/**
 * Diz porque é que uma medição da pulseira não produziu valor, como acontecimento e não
 * como telemetria.
 */
final class MeasurementFailureReporter
{
    /** Quanto tempo a mesma queixa do mesmo aparelho fica calada depois de relatada. */
    private const FAILURE_REPEAT_SECONDS = 60;

    private readonly Throttle $reported;

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
    ) {
        $this->reported = new Throttle();
    }

    /** @param array<string, mixed> $device */
    public function report(
        string $deviceKey,
        array $device,
        int $licenseId,
        string $company,
        string $reason,
    ): void {
        // Uma vez por janela e não por trama: o ECG repete a queixa enquanto não há contacto.
        if (!$this->reported->allow($deviceKey . '|' . $reason, self::FAILURE_REPEAT_SECONDS, time())) {
            return;
        }

        // O motivo vai em `error`: o comando que pediu a medição aqui não se conhece.
        $event = RawPayload::event(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            'device.measurement_failed',
            ['reason' => $reason],
            null,
            (string)($device['commercialName'] ?? ''),
        );

        $this->mqttBridge->publishEvent($deviceKey, $event, 'bracelet', $licenseId, $company);
        $this->deviceStore?->append($deviceKey, 'events', $event + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }
}
