<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Api\Repository\RadarLayoutRepository;
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
            VALUES ('594B3CCBA56B', 'Qinglanst', 'RD-V1', 'radar', 2103)
        ");
    }

    public function testStoresTheRoomAndItsAreas(): void
    {
        $this->repository->store('594B3CCBA56B', $this->layout(), '{"raw":1}', '2026-09-17 15:00:00');

        $layout = $this->repository->findByImei('594B3CCBA56B');

        self::assertNotNull($layout);
        self::assertSame(
            ['x_min_dm' => -30, 'y_min_dm' => -8, 'x_max_dm' => 30, 'y_max_dm' => 20],
            $layout['room'],
        );
        self::assertSame(['CAMA 1', 'Porta'], array_column($layout['areas'], 'name'));
        self::assertSame([0, 4], array_column($layout['areas'], 'key'));
    }

    /**
     * Uma sala reconfigurada com menos áreas não pode deixar as antigas para trás: o mapa
     * passava a desenhar uma cama que já ninguém declarou, e o `regionId` da presença
     * resolvia para o nome errado.
     */
    public function testReplacingALayoutLeavesNoAreasBehind(): void
    {
        $this->repository->store('594B3CCBA56B', $this->layout(), '{"raw":1}', '2026-09-17 15:00:00');

        $this->repository->store('594B3CCBA56B', [
            'room' => ['x_min_dm' => -10, 'y_min_dm' => -6, 'x_max_dm' => 10, 'y_max_dm' => 20],
            'areas' => [
                ['key' => 2, 'type' => 3, 'name' => 'Lavatório', 'x_min_dm' => -9, 'y_min_dm' => -6, 'x_max_dm' => -1, 'y_max_dm' => -2],
            ],
            'skipped' => [],
        ], '{"raw":2}', '2026-09-17 16:00:00');

        $layout = $this->repository->findByImei('594B3CCBA56B');

        self::assertNotNull($layout);
        self::assertSame(['Lavatório'], array_column($layout['areas'], 'name'));
        self::assertSame(-10, $layout['room']['x_min_dm']);
        self::assertSame('2026-09-17 16:00:00', $layout['fetched_at']);
    }

    /** As plantas de vários radares de uma vez: é assim que o ecrã da lista as pede. */
    public function testReadsSeveralLayoutsAtOnce(): void
    {
        $this->pdo->exec("
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
            VALUES ('414D74184CBF', 'Qinglanst', 'RD-V1', 'radar', 2103)
        ");
        $this->repository->store('594B3CCBA56B', $this->layout(), '{}', '2026-09-17 15:00:00');
        $this->repository->store('414D74184CBF', $this->layout(), '{}', '2026-09-17 15:00:00');

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
            'room' => ['x_min_dm' => -30, 'y_min_dm' => -8, 'x_max_dm' => 30, 'y_max_dm' => 20],
            'areas' => [
                ['key' => 0, 'type' => 2, 'name' => 'CAMA 1', 'x_min_dm' => -20, 'y_min_dm' => -8, 'x_max_dm' => -11, 'y_max_dm' => 12],
                ['key' => 4, 'type' => 4, 'name' => 'Porta', 'x_min_dm' => -31, 'y_min_dm' => -8, 'x_max_dm' => -29, 'y_max_dm' => 4],
            ],
            'skipped' => [],
        ];
    }
}
