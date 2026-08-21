<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\DeviceService;
use Hub\Dashboard\DashboardStore;
use Hub\Registry\Whitelist;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Os tres endpoints da sensibilidade do medidor de fraldas.
 *
 * Sub-recurso do dispositivo e nao uma capacidade configuravel, como os links de gateway:
 * o `PATCH /configurations` constroi um downlink e espera um ack, e este sensor e um beacon
 * nao-conectavel a quem nada e enviado. Passar por la deixaria cada alteracao "pendente"
 * para sempre no ecra, para uma configuracao que passa a valer na observacao seguinte.
 *
 * Em ficheiro proprio e nao no `DevicesApiTest`: aquele ja tem 3200 linhas e acrescentar-lhe
 * estes varrimentos punha o phpstan a estourar o limite de memoria.
 */
final class DiaperSensitivityApiTest extends MysqlDashboardTestCase
{
    private const SENSOR = 'eec5000202f9';
    private const BRACELET = 'fbd87c59ba8b';

    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-diaper-sensitivity-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, '{}');
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    private function api(): DeviceService
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard:diaper-sensitivity');
        $store->setDataAccess($db);
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('sent');

        $api = new DeviceService($store, new Whitelist($this->whitelistPath, $db->whitelist), $hub, $db);
        foreach ([
            [self::SENSOR, 'MONIT', 'MECS-PRO'],
            [self::BRACELET, 'MOKO', 'W6R'],
        ] as [$imei, $supplier, $model]) {
            $created = $api->create(json_encode([
                'imei' => $imei, 'supplier' => $supplier, 'model' => $model,
                'licenseId' => '1001', 'company' => 'hitcare',
            ], JSON_THROW_ON_ERROR));
            self::assertSame('ok', $created['status'] ?? null, "devia registar {$imei}");
        }

        return $api;
    }

    /** @param array<string, mixed> $body */
    private function put(DeviceService $api, string $imei, array $body): array
    {
        return $api->updateDiaperSensitivity($imei, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testAnUnconfiguredSensorReportsTheNormalPreset(): void
    {
        // Sao os limiares que o hub tinha em hardcode, e por coincidencia exacta o preset
        // "Normal Diaper Alerts" da app da MONIT.
        $data = $this->api()->diaperSensitivity(self::SENSOR)['data'] ?? [];

        self::assertSame(4, $data['pollutionRange'] ?? null);
        self::assertSame(12, $data['pollutionValue'] ?? null);
        self::assertSame('normal', $data['profile'] ?? null);
    }

    public function testThePresetsAndBoundsTravelInTheResponse(): void
    {
        // Para quem desenha o selector nao manter uma segunda copia destas fronteiras.
        $data = $this->api()->diaperSensitivity(self::SENSOR)['data'] ?? [];

        self::assertSame([2, 10], $data['bounds']['pollutionRange'] ?? null);
        self::assertSame([5, 25], $data['bounds']['pollutionValue'] ?? null);
        self::assertSame(
            ['more_alerts', 'normal', 'fewer_alerts'],
            array_keys($data['presets'] ?? [])
        );
    }

    public function testAPresetIsStoredAndNamedBack(): void
    {
        $api = $this->api();

        $updated = $this->put($api, self::SENSOR, ['pollutionRange' => 3, 'pollutionValue' => 7]);
        self::assertSame('ok', $updated['status'] ?? null);
        self::assertSame('more_alerts', $updated['profile'] ?? null);
        self::assertSame('sensitive', $updated['pollutionRangeGrade'] ?? null);
        self::assertSame('sensitive', $updated['pollutionValueGrade'] ?? null);

        $read = $api->diaperSensitivity(self::SENSOR)['data'] ?? [];
        self::assertSame(3, $read['pollutionRange'] ?? null);
        self::assertSame('more_alerts', $read['profile'] ?? null);
    }

    public function testAnOffPresetPairIsNamedCustomAndNotStoredAsAFourthState(): void
    {
        $custom = $this->put($this->api(), self::SENSOR, ['pollutionRange' => 5, 'pollutionValue' => 9]);

        self::assertSame('custom', $custom['profile'] ?? null);
        self::assertSame(5, $custom['pollutionRange'] ?? null);
        self::assertSame('normal', $custom['pollutionValueGrade'] ?? null);
    }

    public function testDeleteReturnsTheSensorToTheDefault(): void
    {
        $api = $this->api();
        $this->put($api, self::SENSOR, ['pollutionRange' => 7, 'pollutionValue' => 15]);

        self::assertSame('ok', $api->deleteDiaperSensitivity(self::SENSOR)['status'] ?? null);
        self::assertSame('normal', $api->diaperSensitivity(self::SENSOR)['data']['profile'] ?? null);
    }

    /** @return list<array{array<string, mixed>, string}> */
    public static function rejectedBodies(): array
    {
        return [
            'range abaixo do minimo' => [['pollutionRange' => 1, 'pollutionValue' => 12], 'invalid_sensitivity'],
            'range acima do maximo' => [['pollutionRange' => 11, 'pollutionValue' => 12], 'invalid_sensitivity'],
            'value abaixo do minimo' => [['pollutionRange' => 4, 'pollutionValue' => 4], 'invalid_sensitivity'],
            'value acima do maximo' => [['pollutionRange' => 4, 'pollutionValue' => 26], 'invalid_sensitivity'],
            // Um fraccionario nao pode virar inteiro num cast silencioso: isto decide alarmes.
            'range fraccionario' => [['pollutionRange' => 4.5, 'pollutionValue' => 12], 'invalid_sensitivity'],
            'range em falta' => [['pollutionValue' => 12], 'invalid_sensitivity'],
            'value em falta' => [['pollutionRange' => 4], 'invalid_sensitivity'],
            'value em texto' => [['pollutionRange' => 4, 'pollutionValue' => 'doze'], 'invalid_sensitivity'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @dataProvider rejectedBodies
     */
    public function testInvalidValuesAreRejected(array $body, string $code): void
    {
        self::assertSame($code, $this->put($this->api(), self::SENSOR, $body)['error']['code'] ?? null);
    }

    public function testMalformedJsonIsRejected(): void
    {
        self::assertSame(
            'invalid_request',
            $this->api()->updateDiaperSensitivity(self::SENSOR, 'nao json')['error']['code'] ?? null
        );
    }

    public function testSensitivityAppliesToDiaperSensorsOnly(): void
    {
        // Uma pulseira nao tem canais capacitivos, e nada nesta configuracao lhe diz respeito.
        $api = $this->api();

        self::assertSame(
            'invalid_sensitivity',
            $api->diaperSensitivity(self::BRACELET)['error']['code'] ?? null
        );
        self::assertSame(
            'invalid_sensitivity',
            $this->put($api, self::BRACELET, ['pollutionRange' => 3, 'pollutionValue' => 7])['error']['code'] ?? null
        );
    }

    public function testAnUnknownDeviceIsNotFound(): void
    {
        self::assertSame(
            'not_found',
            $this->api()->diaperSensitivity('nao-existe')['error']['code'] ?? null
        );
    }
}
