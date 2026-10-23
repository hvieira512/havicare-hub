<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Closure;
use DateTimeImmutable;
use Hub\Api\Services\DeviceService;
use Hub\Domain\Capability\TimeZoneOffset;
use Hub\Infrastructure\Persistence\Repository\DeviceConfigurationRepository;

/**
 * Os aparelhos com fuso de zona guardam um desvio fixo: quando a hora muda, recebem o desvio
 * novo e a hora certa. Só os ligados, e o seguinte apanha quem estava desligado.
 */
final class NamedTimeZoneFollower
{
    /** @param Closure(): list<string> $onlineImeis */
    public function __construct(
        private DeviceService $devices,
        private DeviceConfigurationRepository $configurations,
        private Closure $onlineImeis,
    ) {
    }

    /** @return list<string> os aparelhos a que o fuso foi reescrito */
    public function tick(): array
    {
        $online = array_flip(($this->onlineImeis)());
        $now = new DateTimeImmutable();
        $changed = [];
        foreach ($this->configurations->namedTimeZones() as ['imei' => $imei, 'zone' => $zone, 'writtenAt' => $writtenAt]) {
            if (!isset($online[$imei]) || TimeZoneOffset::hhmm($zone, $now) === TimeZoneOffset::hhmm($zone, $writtenAt)) {
                continue;
            }

            // O pedido é o mesmo nome: o `resync` fá-lo sair com o desvio novo.
            $this->devices->updateConfigurations($imei, [
                'configurations' => ['time_zone' => ['timeZone' => $zone, 'resync' => true]],
            ]);
            $this->devices->featureRequests->requestFeature($imei, ['capability' => 'calibrate_clock', 'value' => []]);
            $changed[] = $imei;
        }

        return $changed;
    }
}
