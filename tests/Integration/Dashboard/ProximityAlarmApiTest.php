<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Request\DeviceWriteRequest;
use Hub\Api\Services\DeviceService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\ProximityAlarmRepository;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/** O alarme de proximidade é uma configuração do gateway que o hub aplica: nada viaja para o aparelho. */
final class ProximityAlarmApiTest extends MysqlDashboardTestCase
{
    private const GATEWAY = 'c5e390f30bce';
    private const BRACELET = 'fbd87c59ba8b';

    private DeviceStore $store;

    /** @return array{DeviceService, \PDO} */
    private function api(): array
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $this->store = new DeviceStore(new InMemoryRedisClient());
        $this->store->setDataAccess($db);
        $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('sent');

        $api = new DeviceService($this->store, new Whitelist($db->whitelist), $hub, $db);
        foreach ([[self::GATEWAY, 'MKGW4'], [self::BRACELET, 'W6B']] as [$imei, $model]) {
            $created = $api->create(new DeviceWriteRequest(imei: $imei, supplier: 'MOKO', model: $model, licenseId: 1001, company: 'hitcare'));
            self::assertSame('ok', $created['status'] ?? null, 'devia registar ' . $model);
        }

        return [$api, $database->pdo()];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function patch(DeviceService $api, array $value): array
    {
        return $api->updateConfigurations(self::GATEWAY, ['configurations' => ['proximity_alert' => $value]]);
    }

    public function testAGatewayNobodyConfiguredHasTheAlarmOff(): void
    {
        [$api, $pdo] = $this->api();

        self::assertSame('off', (new ProximityAlarmRepository($pdo))->forGateway(self::GATEWAY)['mode']);
        $entry = $api->show(self::GATEWAY, null, '')['capabilities']['alarms']['proximity_alert'] ?? [];
        self::assertSame('off', $entry['value']['mode'] ?? null);
        self::assertSame([-100, -30], $entry['_meta']['bounds']['triggerDbm'] ?? null);
    }

    public function testAValueIsStoredAndAppliedWithoutAnyCommand(): void
    {
        [$api, $pdo] = $this->api();

        $result = $this->patch($api, ['mode' => 'active', 'devices' => [self::BRACELET], 'triggerDbm' => -55]);
        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame([], $result['results'][0]['operations'] ?? null, 'nada vai para o gateway');

        $sync = $api->show(self::GATEWAY, null, '')['configurationSync']['entries']['alarms']['proximity_alert'] ?? [];
        self::assertSame('confirmed', $sync['status'] ?? null);
        self::assertSame(
            ['mode' => 'active', 'devices' => [self::BRACELET], 'triggerDbm' => -55, 'minSamples' => 3, 'cooldownSeconds' => 60],
            (new ProximityAlarmRepository($pdo))->forGateway(self::GATEWAY),
            'a ingestão lê o valor da mesma tabela das outras configurações',
        );
    }

    /** @param array<string, mixed> $value */
    #[DataProvider('invalid')]
    public function testValuesTheRulesCannotUseAreRejected(array $value): void
    {
        [$api] = $this->api();

        self::assertSame('invalid_config', $this->patch($api, $value)['error']['code'] ?? null);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'unknown mode' => [['mode' => 'on']],
            'threshold out of range' => [['mode' => 'active', 'triggerDbm' => -10]],
        ];
    }

    /** O escolhedor de quem dispara mostra a fotografia, e a calibração a marca de quem dispararia. */
    public function testTheGatewaysLinkedDevicesCarryTheImageAndTheCalibrationMark(): void
    {
        [$api] = $this->api();
        self::assertSame('ok', $api->createLink(self::GATEWAY, self::BRACELET)['status'] ?? null);
        $this->store->recordProximityAlarmCandidate(self::BRACELET, self::GATEWAY);

        $linked = $api->show(self::GATEWAY, null, 'https://hub.example')['linkedDevices'][0] ?? [];

        self::assertSame(self::BRACELET, $linked['deviceKey'] ?? null);
        self::assertStringEndsWith('78888c5376784c64ca05b691c4686ecd.jpg', (string)($linked['image'] ?? ''));
        self::assertNotEmpty($linked['proximityAlarmCandidateAt'] ?? null);
    }
}
