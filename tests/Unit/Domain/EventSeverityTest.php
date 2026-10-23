<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\EventSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A gravidade de cada acontecimento: alarme, alerta ou informação. */
final class EventSeverityTest extends TestCase
{
    /** @param array<string, mixed> $data */
    #[DataProvider('severities')]
    public function testEachEventCarriesItsSeverity(string $type, array $data, ?string $expected): void
    {
        self::assertSame($expected, EventSeverity::of($type, $data));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}> */
    public static function severities(): array
    {
        return [
            'help call' => ['help_call', [], 'alarm'],
            'proximity alarm' => ['proximity_alarm', ['gatewayId' => 'c5e390f30bce', 'rssiDbm' => -49], 'alarm'],
            'confirmed fall' => ['fall', ['confirmed' => true, 'posture' => 'lying_down'], 'alarm'],
            'watch fall' => ['fall', ['confirmed' => true], 'alarm'],
            'suspected fall' => ['fall', ['confirmed' => false, 'posture' => 'lying_down'], 'alert'],
            'very high heart rate' => ['heart_rate_high', ['bpm' => 172], 'alarm'],
            'high heart rate' => ['heart_rate_high', ['bpm' => 134], 'alert'],
            'high heart rate at the limit' => ['heart_rate_high', ['bpm' => 160], 'alert'],
            'high heart rate without a value' => ['heart_rate_high', [], 'alert'],
            'very low heart rate' => ['heart_rate_low', ['bpm' => 18], 'alarm'],
            'low heart rate' => ['heart_rate_low', ['bpm' => 32], 'alert'],
            'low heart rate without a value' => ['heart_rate_low', [], 'alert'],
            'left the geofence' => ['zone_exit', ['zone' => 'geofence'], 'alert'],
            'entered the geofence' => ['zone_entry', ['zone' => 'geofence'], 'info'],
            'left the room' => ['zone_exit', ['zone' => 'room'], 'info'],
            'entered the door' => ['zone_entry', ['zone' => 'area', 'areaId' => 2, 'areaName' => 'Porta', 'areaType' => 'door'], 'info'],
            'help call reset' => ['reset', [], 'info'],
            'low battery is maintenance' => ['low_battery', ['percent' => 12], 'info'],
            'missed dose' => ['medication_alarm_change', ['slot' => 3, 'state' => 'missed'], 'alert'],
            'dose taken' => ['medication_alarm_change', ['slot' => 3, 'state' => 'taken'], 'info'],
            'abnormal intake' => ['medication_intake', ['result' => 'abnormal'], 'alert'],
            'intake on time' => ['medication_intake', ['result' => 'on_time'], 'info'],
            'connection' => ['device.connected', [], null],
            'reading' => ['battery', ['percent' => 80], null],
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
