<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Repository\DiaperSensitivityRepository;
use Hub\Api\Services\DeviceService;
use Hub\Dashboard\DashboardStore;
use Hub\Registry\Whitelist;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A sensibilidade do medidor de fraldas, pela via genérica das configurações.
 *
 * Teve três endpoints próprios -- GET, PUT e DELETE em `/diaper-sensitivity` -- por não ser
 * uma capacidade: o pipeline não sabia exprimir uma configuração que não viaja, e sem isso
 * cada alteração ficaria "pendente" para sempre à espera de um ack que nunca chega. O
 * sensor é um beacon BLE não-conectável e nada lhe é enviado.
 *
 * Agora é a `DiaperSensitivityCapability`, marcada com `HubAppliedCapability`, e passa pelo
 * mesmo `PATCH /configurations` que as outras. O que estes testes prendem é o que isso tinha
 * de garantir: que grava, que se dá por aplicada sem comandos, que a ingestão a lê, e que
 * um par fora das gamas é recusado.
 */
final class DiaperSensitivityApiTest extends MysqlDashboardTestCase
{
    private const SENSOR = 'eec5000202f9';

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

    /**
     * O serviço e o PDO da MESMA base de dados: cada `createDashboardDatabase()` clona o
     * template para uma base nova, por isso chamá-lo duas vezes daria dois mundos que não
     * se veem um ao outro.
     *
     * @return array{DeviceService, \PDO}
     */
    private function api(): array
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard:diaper-sensitivity');
        $store->setDataAccess($db);
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('sent');

        $api = new DeviceService($store, new Whitelist($this->whitelistPath, $db->whitelist), $hub, $db);
        $created = $api->create(json_encode([
            'imei' => self::SENSOR, 'supplier' => 'MONIT', 'model' => 'MECS-PRO',
            'licenseId' => '1001', 'company' => 'hitcare',
        ], JSON_THROW_ON_ERROR));
        self::assertSame('ok', $created['status'] ?? null, 'devia registar o sensor');

        return [$api, $pdo];
    }

    /** @param array<string, mixed> $value */
    private function patch(DeviceService $api, array $value): array
    {
        return $api->updateConfigurations(self::SENSOR, json_encode([
            'configurations' => ['diaper_sensitivity' => $value],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function sensitivityEntry(DeviceService $api): array
    {
        $shown = $api->show(self::SENSOR);

        return $shown['capabilities']['settings_system']['diaper_sensitivity'] ?? [];
    }

    public function testAnUnconfiguredSensorReportsTheNormalPreset(): void
    {
        // Sao os limiares que o hub tinha em hardcode, e por coincidencia exacta o preset
        // "Normal Diaper Alerts" da app da MONIT. A ausencia de linha significa normal, que
        // e porque nenhuma migracao fez backfill.
        [$api, $pdo] = $this->api();

        self::assertSame(
            ['pollutionRange' => 4, 'pollutionValue' => 12],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR),
        );

        $entry = $this->sensitivityEntry($api);
        self::assertSame([2, 10], $entry['_meta']['bounds']['pollutionRange'] ?? null);
        self::assertSame([5, 25], $entry['_meta']['bounds']['pollutionValue'] ?? null);
        self::assertSame(
            ['low', 'normal', 'high'],
            array_keys($entry['_meta']['presets'] ?? []),
            'do menos sensivel para o mais, como no ecra'
        );
    }

    public function testAValueIsStoredAndGivenAsAppliedWithoutAnyCommand(): void
    {
        [$api, $pdo] = $this->api();

        $result = $this->patch($api, ['pollutionRange' => 3, 'pollutionValue' => 7]);
        self::assertSame('ok', $result['status'] ?? null);
        // Sem operações: não há nada a caminho do sensor.
        self::assertSame([], $result['results'][0]['operations'] ?? null);

        $entry = $this->sensitivityEntry($api);
        self::assertSame(3, $entry['value']['pollutionRange'] ?? null);
        self::assertSame(7, $entry['value']['pollutionValue'] ?? null);
        // O perfil é derivado dos dois valores e nunca guardado, para não poderem discordar.
        self::assertSame('high', $entry['value']['profile'] ?? null);

        // `confirmed` e não `waiting_device`: o `stage` sem operações marca a linha `acked`,
        // o `pendingStatus` le isso como `applied`, e o `show` apresenta-o como `confirmed`
        // -- que é o mesmo estado de uma configuração que o dispositivo confirmou, e que a
        // interface já desenha como "Aplicado". Não há vocabulário novo nenhum.
        $sync = $api->show(self::SENSOR)['configurationSync']['entries']['settings_system']['diaper_sensitivity'] ?? [];
        self::assertSame('confirmed', $sync['status'] ?? null);
        self::assertFalse($sync['hasUnconfirmedChanges'] ?? true, 'nao ha nada a aguardar');

        self::assertSame(
            ['pollutionRange' => 3, 'pollutionValue' => 7],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR),
            'a ingestao le o valor novo da mesma tabela das outras configuracoes',
        );
    }

    public function testAnOffPresetPairIsNamedCustom(): void
    {
        [$api] = $this->api();

        $this->patch($api, ['pollutionRange' => 6, 'pollutionValue' => 20]);

        self::assertSame('custom', $this->sensitivityEntry($api)['value']['profile'] ?? null);
    }

    /**
     * @dataProvider invalidPairs
     */
    public function testValuesOutsideTheAcceptedRangeAreRejected(int $range, int $value): void
    {
        [$api] = $this->api();

        $result = $this->patch($api, ['pollutionRange' => $range, 'pollutionValue' => $value]);

        // `invalid_config` e não um código próprio: passa pela mesma rejeição das outras
        // capacidades, porque a validacao e o `sanitizeInput` do contrato.
        self::assertSame('invalid_config', $result['error']['code'] ?? null);
    }

    /** @return array<string, array{int, int}> */
    public static function invalidPairs(): array
    {
        return [
            'range abaixo do minimo' => [1, 12],
            'range acima do maximo' => [11, 12],
            'value abaixo do minimo' => [4, 4],
            'value acima do maximo' => [4, 26],
        ];
    }
}
