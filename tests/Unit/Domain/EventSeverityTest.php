<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\EventSeverity;
use PHPUnit\Framework\TestCase;

/** A gravidade de cada acontecimento: alarme, alerta ou informação. */
final class EventSeverityTest extends TestCase
{
    /**
     * @dataProvider severities
     * @param array<string, mixed> $data
     */
    public function testEachEventCarriesItsSeverity(string $type, array $data, ?string $expected): void
    {
        self::assertSame($expected, EventSeverity::of($type, $data));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}> */
    public static function severities(): array
    {
        return [
            'pedido de ajuda' => ['help_call', [], 'alarm'],
            'apneia' => ['apnea', [], 'alarm'],
            'fralda para mudar' => ['change_required', ['previousState' => 'clean'], 'alarm'],
            'queda confirmada' => ['fall', ['confirmed' => true, 'posture' => 'lying'], 'alarm'],
            'queda do relógio' => ['fall', ['confirmed' => true], 'alarm'],
            'queda suspeita' => ['fall', ['confirmed' => false, 'posture' => 'lying'], 'alert'],
            'FC muito alta' => ['heart_rate_high', ['bpm' => 172], 'alarm'],
            'FC alta' => ['heart_rate_high', ['bpm' => 134], 'alert'],
            'FC alta no limite' => ['heart_rate_high', ['bpm' => 160], 'alert'],
            'FC alta sem valor' => ['heart_rate_high', [], 'alert'],
            'FC muito baixa' => ['heart_rate_low', ['bpm' => 18], 'alarm'],
            'FC baixa' => ['heart_rate_low', ['bpm' => 32], 'alert'],
            'FC baixa sem valor' => ['heart_rate_low', [], 'alert'],
            'FC anormal do 4P' => ['heart_rate_abnormal', [], 'alert'],
            'respiração alta' => ['breath_rate_high', ['breathsPerMinute' => 28], 'alert'],
            'respiração baixa' => ['breath_rate_low', [], 'alert'],
            'sinais vitais fracos' => ['weak_vital_signs', [], 'alert'],
            'relógio retirado' => ['device_removed', [], 'alert'],
            'bateria fraca' => ['low_battery', ['percent' => 14], 'alert'],
            'fralda húmida' => ['check_required', ['previousState' => 'clean'], 'alert'],
            'avaria' => ['device_fault', ['fault' => 'pusher'], 'alert'],
            'medicação mal conservada' => ['storage_environment', ['outOfRange' => true], 'alert'],
            'relógio desligado' => ['device_state', ['state' => 'shutdown'], 'alert'],
            'saiu da cerca' => ['zone_exit', ['zone' => 'geofence'], 'alert'],
            'entrou na cerca' => ['zone_entry', ['zone' => 'geofence'], 'info'],
            'saiu da divisão' => ['zone_exit', ['zone' => 'room'], 'info'],
            'entrou na porta' => ['zone_entry', ['zone' => 'area', 'areaId' => 2, 'areaName' => 'Porta', 'areaType' => 'door'], 'info'],
            'chamada reposta' => ['reset', [], 'info'],
            'dose falhada' => ['medication_alarm_change', ['alarm' => 3, 'state' => 'missed'], 'alert'],
            'dose fora de tempo' => ['medication_alarm_change', ['alarm' => 3, 'state' => 'timed_out'], 'alert'],
            'dose por levantar' => ['medication_alarm_change', ['alarm' => 3, 'state' => 'retrieval_timed_out'], 'alert'],
            'dose tomada' => ['medication_alarm_change', ['alarm' => 3, 'state' => 'taken'], 'info'],
            'toma anormal' => ['medication_intake', ['result' => 'abnormal'], 'alert'],
            'toma falhada' => ['medication_intake', ['result' => 'missed'], 'alert'],
            'toma a horas' => ['medication_intake', ['result' => 'on_time'], 'info'],
            'ligação' => ['device.connected', [], null],
            'leitura' => ['battery', ['percent' => 80], null],
        ];
    }

    /** Um acontecimento do catálogo sem gravidade sairia sem ela, e ninguém daria por isso. */
    public function testEveryCatalogEventHasASeverity(): void
    {
        $unclassified = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            if (($definition['isEvent'] ?? false) === true && EventSeverity::of((string)$definition['key'], []) === null) {
                $unclassified[] = $definition['key'];
            }
        }

        self::assertSame([], array_values(array_unique($unclassified)));
    }

    public function testStampingAddsTheSeverityNextToTheType(): void
    {
        $event = EventSeverity::stamp(['type' => 'help_call', 'occurredAt' => 'x', 'data' => []]);

        self::assertSame(['type', 'severity', 'occurredAt', 'data'], array_keys($event));
        self::assertSame('alarm', $event['severity']);
    }

    public function testStampingLeavesALifecycleEventAlone(): void
    {
        $event = ['type' => 'device.connected', 'occurredAt' => 'x'];

        self::assertSame($event, EventSeverity::stamp($event));
    }
}
