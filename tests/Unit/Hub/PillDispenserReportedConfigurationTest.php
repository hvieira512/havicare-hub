<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A resposta ao `0x05` diz o que o aparelho **tem**, e não o que lhe pedimos.
 *
 * O hub mandava a leitura da configuração e deitava fora a resposta: a dashboard mostrava
 * sempre o desejado, e uma escrita que o aparelho tivesse alterado por sua conta -- ou que
 * nunca tivesse chegado -- ficava invisível. Agora cada configuração volta com o seu valor,
 * pela chave do contrato, para a projeção a guardar como reportada.
 *
 * As chaves e as formas são as mesmas com que a configuração é enviada: é o que permite à
 * dashboard desenhar o reportado com o mesmo componente que desenha o desejado, e comparar
 * os dois sem traduzir nada pelo meio.
 */
final class PillDispenserReportedConfigurationTest extends TestCase
{
    public function testEachConfigurationComesBackUnderItsOwnKey(): void
    {
        $settings = $this->readConfiguration([
            0x1021 => "\x08", 0x1031 => "\x1E", 0x1041 => "\x01",   // alarme 1 às 08:30
            0x1023 => "\x14", 0x1033 => "\x00", 0x1043 => "\x01",   // alarme 3 às 20:00
            0x1013 => "\x02",                                        // volume baixo
            0x1012 => "\x01",                                        // toque 1
            0x1001 => "\x00",                                        // idioma do aparelho
            0x1015 => pack('s', 100),                                // Lisboa no verão
            0x100C => "\x01",                                        // bloqueio de criança
            0x100D => "\x00",                                        // sem toma antecipada
        ]);

        self::assertSame(['volume' => 2], $settings['alarm_volume']);
        self::assertSame(['ringtone' => 1], $settings['alarm_ringtone']);
        self::assertSame(['language' => 0], $settings['device_language']);
        self::assertSame(['timeZone' => 100], $settings['time_zone']);
        self::assertSame(['enabled' => true], $settings['child_lock']);
        self::assertSame(['enabled' => false], $settings['early_dispense']);
    }

    /** O plano volta com o número do alarme, senão o 3 aparecia como se fosse o 2. */
    public function testThePlanKeepsTheAlarmNumbers(): void
    {
        $settings = $this->readConfiguration([
            0x1021 => "\x08", 0x1031 => "\x1E", 0x1041 => "\x01",
            0x1023 => "\x14", 0x1033 => "\x00", 0x1043 => "\x01",
        ]);

        self::assertSame(
            ['plans' => [
                ['slot' => 1, 'hour' => 8, 'minute' => 30, 'enabled' => true],
                ['slot' => 3, 'hour' => 20, 'minute' => 0, 'enabled' => true],
            ]],
            $settings['medication_reminders'],
        );
    }

    /**
     * O «não incomodar» faltava por inteiro na leitura.
     *
     * Escrevia-se e nunca se lia de volta: era a única configuração do aparelho sobre a qual
     * o hub não tinha maneira nenhuma de saber o que lá estava.
     */
    public function testQuietHoursComeBack(): void
    {
        $settings = $this->readConfiguration([
            0x1051 => "\x01",
            0x1052 => "\x16",   // 22
            0x1053 => "\x00",
            0x1054 => "\x07",
            0x1055 => "\x1E",   // 30
        ]);

        self::assertSame([
            'enabled' => true,
            'startHour' => 22,
            'startMinute' => 0,
            'endHour' => 7,
            'endMinute' => 30,
        ], $settings['do_not_disturb']);
    }

    /** Uma TAG que o aparelho recusa não pode voltar como valor: é o que não sabemos. */
    public function testARefusedTagDoesNotBecomeAValue(): void
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x85,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x1013 => ['value' => "\x02", 'state' => 0],
                0x1012 => ['value' => "\x00", 'state' => 1],   // TAG inválida
            ],
        ]));
        // O codificador não leva o estado da leitura, que só existe na resposta do aparelho.
        $decoded['tlv'][0x1012]['state'] = 1;

        $settings = $this->settingsOf($decoded);

        self::assertSame(['volume' => 2], $settings['alarm_volume']);
        self::assertArrayNotHasKey('alarm_ringtone', $settings);
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, array<string, mixed>>
     */
    private function readConfiguration(array $tlv): array
    {
        $adapter = new PillDispenserAdapter();
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        return $this->settingsOf($adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x85,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ])));
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, array<string, mixed>>
     */
    private function settingsOf(array $decoded): array
    {
        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);
        self::assertCount(1, $events);
        self::assertSame('device_config', $events[0]['feature']);

        return $events[0]['value']['settings'] ?? [];
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
