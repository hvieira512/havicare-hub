<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\RadarLayoutService;
use Hub\Device\DeviceHubServer;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Http\Qinglanst\LayoutParser;
use Hub\Ingress\Http\Qinglanst\QinglanstApiClient;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStoreContract;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A segunda linha de defesa: o serviço recusa pelo inquilino, e não só a allowlist de rotas.
 */
final class DeviceTenantScopeTest extends MysqlDashboardTestCase
{
    private const OWNER_IMEI = '865028000000401';
    private const RADAR_IMEI = '865028000000402';

    private DeviceService $devices;
    private RadarLayoutService $layouts;
    private Whitelist $whitelist;
    private ApiDataAccess $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->whitelist = new Whitelist(null, $this->db->whitelist);
        $this->devices = new DeviceService(
            $this->createStub(DeviceStoreContract::class),
            $this->whitelist,
            $this->createStub(DeviceHubServer::class),
            $this->db,
        );
        $this->layouts = new RadarLayoutService($this->db, new RadarLayoutSync(
            $this->createStub(QinglanstApiClient::class),
            new LayoutParser(),
            $this->db->radarLayouts,
            $this->db->radarCredentials,
            $this->db->whitelist,
        ));

        $this->whitelist->register(self::OWNER_IMEI, '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $this->whitelist->register(self::RADAR_IMEI, 'Qinglanst', 'RD-V1', 'radar', 1001, '', self::RADAR_IMEI, 'hitcare');
    }

    public function testAnotherTenantCannotDeleteTheDevice(): void
    {
        $result = $this->devices->delete(self::OWNER_IMEI, $this->intruder());

        self::assertSame('not_found', $result['error']['code'] ?? null);
        self::assertNotNull(
            $this->whitelist->getMetadata(self::OWNER_IMEI),
            'o dispositivo tem de continuar registado',
        );
    }

    public function testAnotherTenantCannotCreateADeviceInTheOwnerTenant(): void
    {
        $result = $this->devices->create([
            'imei' => '865028000000403',
            'supplier' => '4P Touch',
            'model' => 'D46',
            'licenseId' => 1001,
            'company' => 'hitcare',
        ], $this->intruder());

        // Aqui não há dispositivo cuja existência esconder: a recusa é directa.
        self::assertSame('forbidden', $result['error']['code'] ?? null);
        self::assertNull($this->whitelist->getMetadata('865028000000403'));
    }

    public function testAnotherTenantCannotReadTheRadarLayout(): void
    {
        $result = $this->layouts->show(self::RADAR_IMEI, $this->intruder());

        self::assertSame('not_found', $result['error']['code'] ?? null);
    }

    /** O mesmo papel do dono, noutra empresa e noutra licença. */
    private function intruder(): ApiAuthContext
    {
        return new ApiAuthContext(
            7,
            'outro',
            ApiAuthContext::ROLE_LICENSE_CLIENT,
            licenseId: 2002,
            licenseRefId: 2,
            companyId: 2,
            company: 'outra',
        );
    }
}
