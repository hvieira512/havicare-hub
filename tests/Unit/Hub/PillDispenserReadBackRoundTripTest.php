<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Command\DeviceCommandCatalog;
use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O que se escreve no aparelho volta a ler-se com o mesmo valor.
 *
 * Um escritor que empacote em `V` e um leitor que desempacote em `v` passam os dois nos seus
 * testes e discordam no meio. Aqui a trama escrita é a que se lê: monta-se o `0x06` com o
 * construtor real e devolve-se como se fosse a resposta `0x86` do aparelho.
 */
final class PillDispenserReadBackRoundTripTest extends TestCase
{
    private const IMEI = '869243062262262';

    public function testTheWarningTimeComesBackInMinutes(): void
    {
        $settings = $this->roundTrip('retrievalWarning', ['minutes' => 45]);

        self::assertSame(['minutes' => 45], $settings['retrieval_warning'] ?? null);
    }

    public function testTheMissedTimeComesBackInMinutes(): void
    {
        $settings = $this->roundTrip('retrievalTimeout', ['minutes' => 90]);

        self::assertSame(['minutes' => 90], $settings['retrieval_timeout'] ?? null);
    }

    public function testTheLoadedCellCountComesBack(): void
    {
        $settings = $this->roundTrip('loadedCells', ['cells' => 14]);

        self::assertSame(['cells' => 14], $settings['loaded_cells'] ?? null);
    }

    /**
     * O ano do período viaja em INT16U e voltava a ser lido como INT16S. Hoje 2026 cabe nos
     * dois, e é por isso que a discordância não dava erro nenhum.
     */
    public function testThePlanPeriodComesBackWithBothDates(): void
    {
        $settings = $this->roundTrip('medicationPeriod', [
            'enabled' => true,
            'startDate' => '2026-09-02',
            'endDate' => '2026-09-04',
        ]);

        self::assertSame([
            'enabled' => true,
            'startDate' => '2026-09-02',
            'endDate' => '2026-09-04',
        ], $settings['medication_period'] ?? null);
    }

    public function testTheAlarmPlanComesBackWithTheHoursItWasGiven(): void
    {
        $settings = $this->roundTrip('medicationPlan', [
            'plans' => [
                ['slot' => 1, 'hour' => 9, 'minute' => 35, 'enabled' => true],
                ['slot' => 4, 'hour' => 20, 'minute' => 0, 'enabled' => true],
            ],
        ]);

        self::assertSame(['plans' => [
            ['slot' => 1, 'hour' => 9, 'minute' => 35, 'enabled' => true],
            ['slot' => 4, 'hour' => 20, 'minute' => 0, 'enabled' => true],
        ]], $settings['medication_reminders'] ?? null);
    }

    /**
     * As configurações de um valor só, todas de uma vez. A trama escrita traz uma TAG e a
     * resposta traz a mesma: se uma delas mudar de tipo ou de escala, fecha aqui.
     *
     * @return iterable<string, array{string, array<string, mixed>, string, array<string, mixed>}>
     */
    public static function singleValueSettings(): iterable
    {
        yield 'volume' => ['alarmVolume', ['volume' => 2], 'alarm_volume', ['volume' => 2]];
        yield 'toque' => ['alarmRingtone', ['ringtone' => 3], 'alarm_ringtone', ['ringtone' => 3]];
        yield 'idioma' => ['deviceLanguage', ['language' => 1], 'device_language', ['language' => 1]];
        yield 'fuso' => ['timeZone', ['timeZone' => 100], 'time_zone', ['timeZone' => 100]];
        yield 'fuso a oeste' => ['timeZone', ['timeZone' => -300], 'time_zone', ['timeZone' => -300]];
        yield 'bloqueio' => ['childLock', ['enabled' => true], 'child_lock', ['enabled' => true]];
        yield 'toma antecipada' => ['earlyRetrieval', ['enabled' => false], 'early_dispense', ['enabled' => false]];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('singleValueSettings')]
    public function testASingleValueSettingComesBackUnchanged(
        string $command,
        array $payload,
        string $key,
        array $expected,
    ): void {
        self::assertSame($expected, $this->roundTrip($command, $payload)[$key] ?? null);
    }

    /**
     * Monta o `0x06` que a escrita produz e lê-o de volta como a resposta `0x86`.
     *
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    private function roundTrip(string $command, array $payload): array
    {
        $adapter = new PillDispenserAdapter();
        $written = $adapter->decodeIncoming(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, $payload)
        );
        self::assertIsArray($written);
        self::assertSame(0x06, $written['packetType']);

        $answer = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x86,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $written['tlv'],
        ]));

        foreach ((new DeviceEventDecoder())->decode($this->session(), $answer) as $event) {
            if ($event['feature'] === 'device_config') {
                return $event['value']['settings'] ?? [];
            }
        }

        self::fail('a resposta não saiu como configuração reportada');
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
