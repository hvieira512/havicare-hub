<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\HubMqttBridge;
use Hub\Device\RawPayload;

/**
 * Diz porque é que uma medição da pulseira não produziu valor.
 *
 * É um acontecimento e não telemetria: não há nada a registar, há uma razão a mostrar. Sem
 * isto, um pedido que morreu por bateria fraca ou por sensor avariado ficava em fila até
 * expirar sem ninguém saber porquê -- que é o tipo de silêncio que faz um cuidador carregar
 * no botão três vezes.
 */
final class MeasurementFailureReporter
{
    /** Quanto tempo a mesma queixa do mesmo aparelho fica calada depois de relatada. */
    private const FAILURE_REPEAT_SECONDS = 60;

    /**
     * Quando cada par aparelho/motivo foi relatado pela última vez.
     *
     * @var array<string, int>
     */
    private array $lastFailureAt = [];

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DashboardStoreContract $dashboardStore,
    ) {
    }

    /**
     * Diz porque é que uma medição não produziu valor.
     *
     * É um acontecimento e não telemetria: não há nada a registar, há uma razão a mostrar.
     *
     * @param array<string, mixed> $device
     */
    public function report(
        string $deviceKey,
        array $device,
        int $licenseId,
        string $company,
        string $reason,
    ): void {
        // Uma medição falhada não é um acontecimento por trama. O ECG manda dezenas seguidas
        // com a mesma queixa enquanto o dedo não está no elétrodo, e relatar cada uma
        // afogava o histórico do aparelho no aviso em vez de o mostrar.
        $key = $deviceKey . '|' . $reason;
        $now = time();
        if ($now - ($this->lastFailureAt[$key] ?? 0) < self::FAILURE_REPEAT_SECONDS) {
            return;
        }
        $this->lastFailureAt[$key] = $now;

        // O motivo vai em `error` e não em `command`: descreve porque é que a medição não
        // saiu, e não o comando que a pediu -- que aqui nem sequer se conhece.
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
        $this->dashboardStore?->append($deviceKey, 'events', $event + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }
}
