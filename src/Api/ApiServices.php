<?php

declare(strict_types=1);

namespace Hub\Api;

use Hub\Api\Services\ApiUserService;
use Hub\Api\Services\AuthService;
use Hub\Api\Services\CapabilityDiscoveryService;
use Hub\Api\Services\CapabilityService;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DashboardNotificationService;
use Hub\Api\Services\DenylistService;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Api\Services\ProtocolService;
use Hub\Api\Services\RadarCredentialsService;
use Hub\Api\Services\RadarLayoutService;
use Hub\Api\Services\SupplierService;

/**
 * Os serviços que servem as rotas, num objecto só.
 *
 * É o mesmo que o `ApiDataAccess` faz aos repositórios: cada rota vai buscar o seu por nome,
 * e acrescentar um serviço deixa de obrigar a mexer na assinatura do `ApiKernel`.
 */
final class ApiServices
{
    public function __construct(
        public readonly AuthService $auth,
        public readonly DeviceService $devices,
        public readonly ModelService $models,
        public readonly CapabilityService $capabilities,
        public readonly CapabilityDiscoveryService $capabilityDiscovery,
        public readonly SupplierService $suppliers,
        public readonly ApiUserService $apiUsers,
        public readonly CompanyService $company,
        public readonly LicenseService $licenses,
        public readonly RadarCredentialsService $radarCredentials,
        public readonly RadarLayoutService $radarLayouts,
        public readonly ProtocolService $protocols,
        public readonly DashboardNotificationService $notifications,
        public readonly DenylistService $denylist,
    ) {
    }
}
