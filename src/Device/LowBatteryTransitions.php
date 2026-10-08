<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * Deriva o `low_battery` dos aparelhos que repetem a bandeira em cada leitura: sai quando ela
 * acende, e volta a poder sair depois de ela apagar. O alarme que o próprio aparelho dispara
 * passa sempre, e conta como a bandeira acesa.
 */
final class LowBatteryTransitions
{
    // ponytail: em memória, por processo; um reinício repete o alerta uma vez, e quem consome a
    // QoS 1 já tolera repetidos. Passar para o Redis se isso deixar de ser aceitável.
    /** @var array<string, bool> */
    private array $low = [];

    /**
     * @param array<string, mixed> $event o envelope que acabou de sair
     * @return array<string, mixed>|null o `low_battery` a publicar a seguir, se a bandeira acendeu
     */
    public function observe(string $deviceKey, array $event): ?array
    {
        $type = $event['type'] ?? null;
        if ($type === 'low_battery') {
            $this->low[$deviceKey] = true;
            return null;
        }

        $flag = $type === 'battery' ? ($event['data']['lowBattery'] ?? null) : null;
        if (!is_bool($flag)) {
            return null;
        }

        $wasLow = $this->low[$deviceKey] ?? false;
        $this->low[$deviceKey] = $flag;
        if (!$flag || $wasLow) {
            return null;
        }

        $data = $event['data'];

        return [
            'type' => 'low_battery',
            'data' => array_intersect_key($data, ['percent' => true, 'voltageMv' => true]),
        ] + $event;
    }
}
