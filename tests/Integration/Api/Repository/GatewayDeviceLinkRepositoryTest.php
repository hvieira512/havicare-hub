<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Infrastructure\Persistence\Repository\GatewayDeviceLinkRepository;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** O portão que decide se um gateway pode retransmitir uma pulseira, e a cache dele. */
final class GatewayDeviceLinkRepositoryTest extends MysqlDashboardTestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const BRACELET = 'fbd87c59ba8b';
    private const OTHER_BRACELET = 'aa11bb22cc33';

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->createDashboardDatabase()->pdo();
        $this->register(self::GATEWAY, 'MOKO', 'MKGW3', 'gateway');
        $this->register(self::BRACELET, 'MOKO', 'W6B', 'bracelet');
        $this->register(self::OTHER_BRACELET, 'MOKO', 'W6', 'bracelet');
    }

    /** Sem ligação declarada o gateway não retransmite: o portão fecha por omissão. */
    public function testAPairWithoutALinkIsNotEnabled(): void
    {
        self::assertFalse($this->repository()->isEnabled(self::GATEWAY, self::BRACELET));
    }

    public function testALinkedPairIsEnabled(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);

        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));
    }

    /** A ligação é do par e tem sentido: ligar um aparelho não liga o do lado nem o inverso. */
    public function testTheLinkBelongsToTheOrderedPair(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);

        self::assertFalse($repository->isEnabled(self::GATEWAY, self::OTHER_BRACELET));
        self::assertFalse($repository->isEnabled(self::BRACELET, self::GATEWAY));
    }

    public function testDeletingTheLinkClosesTheGate(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);
        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));

        $repository->delete(self::GATEWAY, self::BRACELET);

        self::assertFalse($repository->isEnabled(self::GATEWAY, self::BRACELET), 'a cache não pode sobreviver ao delete');
        self::assertSame(0, $this->linkCount());
    }

    /** Apagar um par que não estava ligado não leva outro à frente. */
    public function testDeletingAPairThatWasNotLinkedLeavesTheOthersAlone(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);

        $repository->delete(self::GATEWAY, self::OTHER_BRACELET);

        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));
    }

    /**
     * A resposta vale pela janela da cache: uma alteração feita por fora não é vista já, e é
     * esse o preço de não ir ao MySQL a cada observação BLE.
     */
    public function testAnAnswerIsCachedForTheTtl(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);
        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));

        $this->pdo->exec('DELETE FROM gateway_device_links');

        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET), 'devia responder da cache');
    }

    /**
     * Passada a janela, a resposta volta ao MySQL -- senão o portão ficava preso ao que leu
     * no arranque e desligar uma pulseira nunca chegava à ingestão.
     */
    public function testOnceTheWindowHasPassedTheAnswerIsReadAgain(): void
    {
        $repository = new GatewayDeviceLinkRepository($this->pdo, cacheTtlSeconds: 0);
        $repository->upsert(self::GATEWAY, self::BRACELET);
        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));

        $this->pdo->exec('DELETE FROM gateway_device_links');
        usleep(1_100_000);

        self::assertFalse($repository->isEnabled(self::GATEWAY, self::BRACELET));
    }

    /** Ligar um par responde já: o portão não pode abrir só na janela seguinte. */
    public function testUpsertIsVisibleToTheNextAnswer(): void
    {
        $repository = $this->repository();
        self::assertFalse($repository->isEnabled(self::GATEWAY, self::BRACELET));

        $repository->upsert(self::GATEWAY, self::BRACELET);

        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));
    }

    /** A cache é por par: a resposta de um não pode responder pelo outro. */
    public function testTheCacheDoesNotAnswerForAnotherPair(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);

        self::assertTrue($repository->isEnabled(self::GATEWAY, self::BRACELET));
        self::assertFalse($repository->isEnabled(self::GATEWAY, self::OTHER_BRACELET));
    }

    /**
     * As ligações de um aparelho, vistas dos dois lados: o `deviceKey` é sempre o do outro, e
     * é dele que vêm o fornecedor, o modelo e o dono.
     */
    public function testForDeviceListsBothDirectionsWithTheOtherDeviceDetails(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);
        $repository->upsert(self::OTHER_BRACELET, self::GATEWAY);

        $links = $repository->forDevice(self::GATEWAY);

        self::assertSame([self::OTHER_BRACELET, self::BRACELET], array_column($links, 'deviceKey'));
        self::assertSame(['W6', 'W6B'], array_column($links, 'model'));
        self::assertSame(['bracelet', 'bracelet'], array_column($links, 'deviceType'));
        self::assertSame([1001, 1001], array_map('intval', array_column($links, 'licenseId')));
        self::assertSame(['hitcare', 'hitcare'], array_column($links, 'company'));
    }

    /** Um aparelho sem ligações devolve uma lista vazia, e não as de mais ninguém. */
    public function testForDeviceOfAnUnlinkedDeviceIsEmpty(): void
    {
        $repository = $this->repository();
        $repository->upsert(self::GATEWAY, self::BRACELET);

        self::assertSame([], $repository->forDevice(self::OTHER_BRACELET));
    }

    private function repository(): GatewayDeviceLinkRepository
    {
        return new GatewayDeviceLinkRepository($this->pdo);
    }

    private function register(string $imei, string $supplier, string $model, string $deviceType): void
    {
        $this->pdo->prepare('
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id, company)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$imei, $supplier, $model, $deviceType, 1001, 'hitcare']);
    }

    private function linkCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM gateway_device_links')->fetchColumn();
    }
}
