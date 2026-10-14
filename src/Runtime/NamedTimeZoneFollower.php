<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Closure;
use Hub\Api\Services\DeviceService;
use Hub\Infrastructure\Persistence\Repository\DeviceConfigurationLifecycleRepository;

/**
 * Os aparelhos com fuso de zona guardam um desvio fixo: quando a hora muda, recebem o desvio
 * novo e a hora certa. Só os ligados, e o seguinte apanha quem estava desligado.
 */
final class NamedTimeZoneFollower
{
    /** @param Closure(): list<string> $onlineImeis */
    public function __construct(
        private DeviceService $devices,
        private DeviceConfigurationLifecycleRepository $configurations,
        private Closure $onlineImeis,
    ) {
    }

    /** @return list<string> os aparelhos a que o fuso foi reescrito */
    public function tick(): array
    {
        $online = array_flip(($this->onlineImeis)());
        $changed = [];
        foreach ($this->configurations->namedTimeZones() as ['imei' => $imei, 'zone' => $zone]) {
            if (!isset($online[$imei])) {
                continue;
            }

            // O hub não reenvia um valor igual ao guardado: só sai alguma coisa quando o desvio mudou.
            $result = $this->devices->updateConfigurations($imei, [
                'configurations' => ['time_zone' => ['timeZone' => $zone]],
            ]);
            if (($result['results'] ?? []) === []) {
                continue;
            }

            $this->devices->requestFeature($imei, ['capability' => 'calibrate_clock', 'value' => []]);
            $changed[] = $imei;
        }

        return $changed;
    }
}
