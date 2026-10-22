<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Device\CommercialModelResolver;
use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\State\DeviceStoreContract;

/** O `VeepooBridge` dos testes: um gateway e uma MF91 na whitelist, sem broker. */
final class VeepooFixtures
{
    public const GATEWAY = 'bef341903987';
    public const BRACELET = '9f69c4866e6c';

    /** Uma mensagem do nó Veepoo no tópico raw do gateway. */
    public static function message(string $kind, mixed $payload, string $mac = self::BRACELET): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => $kind,
            'device' => ['mac' => $mac],
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key, array<string, mixed>>|null $devices a whitelist, por omissão o gateway e a pulseira */
    public static function bridge(
        ?RecordingHubMqttBridge $mqtt = null,
        ?PendingDownlinkQueue $queue = null,
        ?DeviceStoreContract $store = null,
        ?callable $clock = null,
        ?ObservationStateStore $state = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        bool $linked = true,
        ?array $devices = null,
    ): VeepooBridge {
        return new VeepooBridge(
            IngressFixtures::whitelist($devices ?? [
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt ?? new RecordingHubMqttBridge(),
            IngressFixtures::links($linked),
            $queue,
            $state ?? new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
            deviceStore: $store,
            clock: $clock,
            commercialModelResolver: $commercialModelResolver,
        );
    }
}
