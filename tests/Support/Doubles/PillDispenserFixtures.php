<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;

/** A sessão do dispensador M228 e as tramas TLV que os testes lhe dão a descodificar. */
final class PillDispenserFixtures
{
    public const MAC = 'AABBCCDDEEFF';

    public static function session(string $imei = self::MAC): DeviceSession
    {
        return new DeviceSession(
            new FakeConnection(),
            true,
            $imei,
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }

    /**
     * Uma trama codificada e descodificada pelo adaptador; um valor solto vai como `['value' => ...]`.
     *
     * @param array<int, string|array{value: string, state?: int}> $tlv
     * @return array<string, mixed>
     */
    public static function frame(int $packetType, array $tlv): array
    {
        $entries = [];
        foreach ($tlv as $tag => $item) {
            $entries[$tag] = is_array($item) ? $item : ['value' => $item];
        }

        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => $packetType,
            'mac' => self::MAC,
            'tlv' => $entries,
        ]));
        assert(is_array($decoded));

        return $decoded;
    }

    /**
     * O valor de cada feature que a trama dá, indexado pela feature.
     *
     * @param array<int, string|array{value: string, state?: int}> $tlv
     * @return array<string, array<string, mixed>>
     */
    public static function features(int $packetType, array $tlv): array
    {
        $byFeature = [];
        foreach ((new DeviceEventDecoder())->decode(self::session(), self::frame($packetType, $tlv)) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        return $byFeature;
    }
}
