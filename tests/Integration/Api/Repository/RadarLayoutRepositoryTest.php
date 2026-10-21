<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Infrastructure\Persistence\Repository\RadarLayoutRepository;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

final class RadarLayoutRepositoryTest extends MysqlDashboardTestCase
{
    private RadarLayoutRepository $repository;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->createDashboardDatabase()->pdo();
        $this->repository = new RadarLayoutRepository($this->pdo);
        $this->pdo->exec("
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
            VALUES ('594B3CCBA56B', 'Qinglanst', 'HC2', 'radar', 2103)
        ");
    }

    public function testStoresTheRoomAndItsAreas(): void
    {
        $this->repository->store('594B3CCBA56B', $this->layout(), '2026-09-17 15:00:00');

        $layout = $this->repository->findByImei('594B3CCBA56B');

        self::assertNotNull($layout);
        self::assertSame(
            ['xMinDm' => -30, 'yMinDm' => -8, 'xMaxDm' => 30, 'yMaxDm' => 20],
            $layout['room'],
        );
        self::assertSame(['CAMA 1', 'Porta'], array_column($layout['areas'], 'name'));
        self::assertSame([0, 4], array_column($layout['areas'], 'key'));
    }

    /** As áreas antigas desenhariam uma cama que ninguém declarou, e o `regionId` resolveria mal. */
    public function testReplacingALayoutLeavesNoAreasBehind(): void
    {
        $this->repository->store('594B3CCBA56B', $this->layout(), '2026-09-17 15:00:00');

        $this->repository->store('594B3CCBA56B', [
            'room' => ['xMinDm' => -10, 'yMinDm' => -6, 'xMaxDm' => 10, 'yMaxDm' => 20],
            'areas' => [
                ['key' => 2, 'type' => 3, 'name' => 'Lavatório', 'xMinDm' => -9, 'yMinDm' => -6, 'xMaxDm' => -1, 'yMaxDm' => -2],
            ],
            'skipped' => [],
        ], '2026-09-17 16:00:00');

        $layout = $this->repository->findByImei('594B3CCBA56B');

        self::assertNotNull($layout);
        self::assertSame(['Lavatório'], array_column($layout['areas'], 'name'));
        self::assertSame(-10, $layout['room']['xMinDm']);
        self::assertSame('2026-09-17 16:00:00', $layout['fetchedAt']);
    }

    /** As plantas de vários radares de uma vez: é assim que o ecrã da lista as pede. */
    public function testReadsSeveralLayoutsAtOnce(): void
    {
        $this->pdo->exec("
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
            VALUES ('414D74184CBF', 'Qinglanst', 'HC2', 'radar', 2103)
        ");
        $this->repository->store('594B3CCBA56B', $this->layout(), '2026-09-17 15:00:00');
        $this->repository->store('414D74184CBF', $this->layout(), '2026-09-17 15:00:00');

        $layouts = $this->repository->findByImeis(['594B3CCBA56B', '414D74184CBF', 'nao-existe']);

        self::assertSame(['414D74184CBF', '594B3CCBA56B'], array_keys($layouts));
        self::assertCount(2, $layouts['414D74184CBF']['areas']);
    }

    public function testUnknownRadarHasNoLayout(): void
    {
        self::assertNull($this->repository->findByImei('594B3CCBA56B'));
        self::assertSame([], $this->repository->findByImeis([]));
    }

    /** @return array<string, mixed> */
    private function layout(): array
    {
        return [
            'room' => ['xMinDm' => -30, 'yMinDm' => -8, 'xMaxDm' => 30, 'yMaxDm' => 20],
            'areas' => [
                ['key' => 0, 'type' => 2, 'name' => 'CAMA 1', 'xMinDm' => -20, 'yMinDm' => -8, 'xMaxDm' => -11, 'yMaxDm' => 12],
                ['key' => 4, 'type' => 4, 'name' => 'Porta', 'xMinDm' => -31, 'yMinDm' => -8, 'xMaxDm' => -29, 'yMaxDm' => 4],
            ],
            'skipped' => [],
        ];
    }
}
