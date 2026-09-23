<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * As cinco avarias que o aparelho sabe reportar, uma a uma.
 *
 * Só a reposição do prato tinha teste. A rotação — o prato encravado, que é a avaria que
 * impede a medicação de sair — podia deixar de ser descodificada sem nada ficar vermelho.
 */
final class PillDispenserFaultsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function faults(): iterable
    {
        yield 'rotação do prato' => [0x8121, 'rotation'];
        yield 'reposição do prato' => [0x8122, 'tray_reset'];
        yield 'empurrador' => [0x8123, 'pusher'];
        yield 'porta do compartimento' => [0x8124, 'cell_door'];
        yield 'teclas' => [0x8125, 'keys'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('faults')]
    public function testEachFaultBecomesItsOwnEvent(int $tag, string $fault): void
    {
        self::assertSame(['fault' => $fault], $this->decode([$tag => "\x01"])['device_fault'] ?? null);
    }

    /** Um estado a zero é o normal, e não uma avaria a zero. */
    #[\PHPUnit\Framework\Attributes\DataProvider('faults')]
    public function testAHealthyStateIsNotAFault(int $tag): void
    {
        self::assertArrayNotHasKey('device_fault', $this->decode([$tag => "\x00"]));
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, array<string, mixed>>
     */
    private function decode(array $tlv): array
    {
        $adapter = new PillDispenserAdapter();
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ]));

        $byFeature = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        return $byFeature;
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            'AABBCCDDEEFF',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
