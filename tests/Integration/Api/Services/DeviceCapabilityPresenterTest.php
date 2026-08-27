<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\DeviceCapabilityPresenter;
use Hub\Domain\Capability\CapabilityRegistry;
use Tests\Support\MysqlDashboardTestCase;

/**
 * As regras que a projecção de capacidades segue, afirmadas contra a própria projecção e não
 * contra o texto-fonte do apresentador -- que se partia em refactors sem alteração nenhuma
 * na saída.
 */
final class DeviceCapabilityPresenterTest extends MysqlDashboardTestCase
{
    private ApiDataAccess $db;
    private DeviceCapabilityPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->presenter = new DeviceCapabilityPresenter(new CapabilityRegistry(), $this->db);
    }

    public function testSupportedCapabilitiesAreServedBeforeAnythingIsStored(): void
    {
        $capabilities = $this->presenter->deviceCapabilities($this->model('Wonlex', 'HW20PRO'), 'wonlex-json', []);

        self::assertArrayHasKey('alarm_clock', $capabilities['alarms']);
        self::assertArrayHasKey('value', $capabilities['alarms']['alarm_clock']);
        self::assertArrayHasKey('_meta', $capabilities['alarms']['alarm_clock']);
    }

    public function testDefaultsAreOmittedWhenTheCallerAsksForStoredValuesOnly(): void
    {
        $model = $this->model('Wonlex', 'HW20PRO');

        $withDefaults = $this->presenter->deviceCapabilities($model, 'wonlex-json', []);
        $storedOnly = $this->presenter->deviceCapabilitiesFromPayloadKey($model, 'wonlex-json', [], 'desired_payload', false);

        self::assertNotSame([], $withDefaults['alarms']);
        self::assertSame([], $storedOnly['alarms'], 'nothing is stored, so nothing is reported as configured');
    }

    public function testACapabilityIsNotServedForAProtocolItsContractRejects(): void
    {
        $wonlex = $this->presenter->deviceCapabilities($this->model('Wonlex', 'HW20PRO'), 'wonlex-json', []);
        $fourPTouch = $this->presenter->deviceCapabilities($this->model('4P Touch', 'D46'), 'four-p-touch', []);

        self::assertArrayNotHasKey(
            'call_whitelist',
            $wonlex['contacts'],
            'call_whitelist is a Vivistar and 4P Touch capability'
        );
        self::assertArrayHasKey('call_whitelist', $fourPTouch['contacts']);
    }

    public function testAStoredValueReplacesTheDefault(): void
    {
        $model = $this->model('Wonlex', 'HW20PRO');
        $rows = [[
            'native_key' => 'reminders',
            'config_key' => 'alarm_clock',
            'desired_payload' => ['items' => [['time' => '07:30', 'enabled' => true, 'recurrence' => ['kind' => 'daily']]]],
            'desired_updated_at' => '2026-01-01 10:00:00',
            'last_status' => 'acked',
        ]];

        $alarm = $this->presenter->deviceCapabilities($model, 'wonlex-json', $rows)['alarms']['alarm_clock'];

        self::assertSame('07:30', $alarm['value'][0]['time'] ?? null);
    }

    public function testRowsWhoseKeyResolvesToNoCapabilityAreIgnored(): void
    {
        $model = $this->model('Wonlex', 'HW20PRO');
        $rows = [[
            'native_key' => 'somethingUnknown',
            'config_key' => 'not_a_capability',
            'desired_payload' => ['value' => 1],
            'desired_updated_at' => '2026-01-01 10:00:00',
            'last_status' => 'acked',
        ]];

        $stored = $this->presenter->deviceCapabilitiesFromPayloadKey($model, 'wonlex-json', $rows, 'desired_payload', false);

        foreach ($stored as $section => $entries) {
            self::assertArrayNotHasKey('not_a_capability', $entries, "leaked into {$section}");
        }
    }

    public function testTelemetryReportsWhatTheModelSupportsAndWhatCanBeRequested(): void
    {
        $telemetry = $this->presenter->telemetryCapabilities($this->model('Wonlex', 'HW20PRO'), 'wonlex-json');

        self::assertNotSame([], $telemetry);
        foreach ($telemetry as $key => $entry) {
            self::assertTrue($entry['supported'], "{$key} is listed, so it is supported");
            self::assertIsBool($entry['requestable']);
        }

        $requestable = array_keys(array_filter($telemetry, static fn(array $e): bool => $e['requestable']));
        $commands = array_map(
            static fn(array $c): string => (string)($c['feature'] ?? ''),
            $this->presenter->enabledRequestCommandsForModel($this->model('Wonlex', 'HW20PRO'), 'wonlex-json')
        );

        foreach ($requestable as $feature) {
            self::assertContains($feature, $commands, 'requestable telemetry must have a request command');
        }
    }

    public function testTelemetryIsNeverWrappedAsAConfigurableCapability(): void
    {
        $capabilities = $this->presenter->deviceCapabilities($this->model('Wonlex', 'HW20PRO'), 'wonlex-json', []);

        foreach ($capabilities['telemetry'] as $key => $entry) {
            self::assertArrayNotHasKey('value', $entry, "{$key} is telemetry, not a stored value");
            self::assertArrayHasKey('supported', $entry);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function model(string $supplier, string $internalModel): array
    {
        $model = $this->db->models->find($supplier, $internalModel);
        self::assertIsArray($model, "{$supplier} {$internalModel} is expected in the seeded catalog");

        return $model;
    }
}
