<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\RadarLayoutService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Mqtt\Qinglanst\Cloud\LayoutParser;
use Hub\Ingress\Mqtt\Qinglanst\Cloud\QinglanstApiClient;
use Hub\Ingress\Mqtt\Qinglanst\Cloud\RadarLayoutSync;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

/** As regras das áreas de um radar são do servidor: a dashboard só mostra o que ele recusa. */
final class RadarAreaRulesTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    private const IMEI = '594B3CF100A7';

    private ?ApiDataAccess $db = null;

    /** @return array<string, mixed> */
    private static function area(int $key, string $type, int $xMin, int $yMin, int $xMax, int $yMax): array
    {
        return ['key' => $key, 'type' => $type, 'name' => null, 'xMinDm' => $xMin, 'yMinDm' => $yMin, 'xMaxDm' => $xMax, 'yMaxDm' => $yMax];
    }

    /** @return array<string, array{0: list<array<string, mixed>>, 1: array<string, list<string>>}> */
    public static function refusedAreas(): array
    {
        $bed = self::area(0, 'monitoring_bed', -5, 5, 5, 20);

        return [
            'door away from every wall' => [[$bed, self::area(1, 'door', 0, 22, 3, 25)], ['items[1]' => ['door_off_wall']]],
            'area outside the room' => [[$bed, self::area(1, 'furniture', 15, 25, 25, 28)], ['items[1]' => ['outside_room']]],
            'second monitoring bed' => [[$bed, self::area(1, 'monitoring_bed', 6, 5, 12, 20)], ['items[1]' => ['second_monitoring_bed']]],
            'more areas than the radar keeps' => [
                array_map(static fn (int $key): array => self::area($key, 'furniture', -1, 1, 1, 2), range(0, 16)),
                ['items' => ['too_many_areas']],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, list<string>> $fields
     */
    #[DataProvider('refusedAreas')]
    public function testABrokenRuleIsRefusedWithTheAreaThatBreaksIt(array $items, array $fields): void
    {
        $api = $this->radarApi();

        $result = $api->updateConfigurations(self::IMEI, ['configurations' => ['radar_areas' => ['items' => $items]]]);

        self::assertSame('invalid_radar_areas', $result['error']['code'] ?? null);
        self::assertSame($fields, $result['error']['fields'] ?? null);
        self::assertSame(422, ApiError::declaredStatus('invalid_radar_areas'));
    }

    public function testTheLayoutSaysHowManyAreasTheRadarKeeps(): void
    {
        $this->radarApi();

        self::assertSame(16, $this->radarLayoutService()->show(self::IMEI)['data']['maxAreas'] ?? null);
    }

    private function radarApi(): DeviceService
    {
        [$api, $db] = $this->makeApi();
        $this->db = $db;
        $db->whitelist->register(self::IMEI, 'Qinglanst', 'HC2', 'radar', 1001, '', self::IMEI, 'hitcare');
        $db->radarLayouts->store(self::IMEI, [
            'room' => ['xMinDm' => -20, 'yMinDm' => 0, 'xMaxDm' => 20, 'yMaxDm' => 30],
            'areas' => [],
        ], '2026-10-21 10:00:00');

        return $api;
    }

    private function radarLayoutService(): RadarLayoutService
    {
        self::assertNotNull($this->db);

        return new RadarLayoutService($this->db, new RadarLayoutSync(
            $this->createStub(QinglanstApiClient::class),
            new LayoutParser(),
            $this->db->radarLayouts,
            $this->db->radarCredentials,
            $this->db->whitelist,
        ));
    }
}
