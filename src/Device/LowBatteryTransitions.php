<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\State\ActiveAlarms;

/**
 * Deriva o `low_battery` dos aparelhos que repetem a bandeira em cada leitura: sai quando ela
 * acende, e volta a poder sair depois de ela apagar. O alarme que o próprio aparelho dispara
 * passa sempre, e conta como a bandeira acesa.
 */
final class LowBatteryTransitions
{
    private const FIELD = 'low_battery';

    /**
     * A bateria fraca só apaga com uma carga, que chega como leitura com a bandeira em baixo; um
     * dia sem a bandeira acesa e fraca outra vez já merece novo aviso.
     */
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly ActiveAlarms $alarms = new ActiveAlarms())
    {
    }

    /**
     * @param array<string, mixed> $event o envelope que acabou de sair
     * @return array<string, mixed>|null o `low_battery` a publicar a seguir, se a bandeira acendeu
     */
    public function observe(string $deviceKey, array $event): ?array
    {
        $type = $event['type'] ?? null;
        if ($type === 'low_battery') {
            $this->alarms->set($deviceKey, self::FIELD, '1', self::TTL_SECONDS);
            return null;
        }

        $flag = $type === 'battery' ? ($event['data']['lowBattery'] ?? null) : null;
        if (!is_bool($flag)) {
            return null;
        }

        $wasLow = $this->alarms->get($deviceKey, self::FIELD) !== null;
        if ($flag || $wasLow) {
            $this->alarms->set($deviceKey, self::FIELD, $flag ? '1' : null, self::TTL_SECONDS);
        }
        if (!$flag || $wasLow) {
            return null;
        }

        $data = $event['data'];

        return [
            'type' => 'low_battery',
            'data' => array_intersect_key($data, ['percent' => true, 'voltageMv' => true]),
        ] + $event;
    }

    public function forget(string $deviceKey): void
    {
        $this->alarms->set($deviceKey, self::FIELD, null, 0);
    }
}
