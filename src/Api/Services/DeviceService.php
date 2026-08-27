<?php

namespace Hub\Api\Services;

use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\DevicePresentation;
use Hub\Api\Http\DeviceResponseCompactor;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Dashboard\DeviceUpdateNotifier;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\DiaperSensitivity;
use Hub\DeviceHubServer;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;

class DeviceService
{
    /** Os tipos que um gateway retransmite por BLE. Espelha o `GATEWAY_LINKED_DEVICE_TYPES`. */
    private const GATEWAY_LINKED_DEVICE_TYPES = ['diaper_sensor', 'bracelet'];

    private CollectionQuery $query;
    private DevicePresentation $presentation;
    private DeviceResponseCompactor $responseCompactor;
    private CapabilityRegistry $capabilityRegistry;
    private DeviceConfigurationUpdateService $configurationUpdates;
    private DeviceConfigurationQueryService $configurationQueries;
    private DeviceAssociationService $associations;
    private DeviceCapabilityPresenter $capabilities;
    private ConfigurationSyncStatus $configurationSync;
    private DeviceDirectory $directory;
    private DeviceFeatureRequestService $featureRequests;

    public function __construct(
        private DashboardStoreContract $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?DevicePresentation $presentation = null,
        ?CapabilityRegistry $capabilityRegistry = null,
        ?DeviceConfigurationUpdateService $configurationUpdates = null,
        ?DeviceConfigurationQueryService $configurationQueries = null,
        ?DeviceResponseCompactor $responseCompactor = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->presentation = $presentation ?? new DevicePresentation();
        $this->responseCompactor = $responseCompactor ?? new DeviceResponseCompactor();
        $this->capabilityRegistry = $capabilityRegistry ?? new CapabilityRegistry();
        $this->configurationUpdates = $configurationUpdates ?? new DeviceConfigurationUpdateService(
            $this->store,
            $this->hub,
            $this->db,
            $this->capabilityRegistry,
        );
        $this->configurationQueries = $configurationQueries ?? new DeviceConfigurationQueryService(
            $this->db,
            $this->capabilityRegistry,
        );
        $this->associations = new DeviceAssociationService($this->store, $this->whitelist, $this->db, $this->hub);
        // Construído aqui e não injectado: é uma projecção do mesmo registo e da mesma base
        // de dados que este serviço já tem.
        $this->capabilities = new DeviceCapabilityPresenter($this->capabilityRegistry, $this->db);
        $this->configurationSync = new ConfigurationSyncStatus();
        $this->directory = new DeviceDirectory($this->store, $this->whitelist, $this->db);
        $this->featureRequests = new DeviceFeatureRequestService(
            $this->store,
            $this->whitelist,
            $this->hub,
            $this->db,
            $this->capabilityRegistry,
            $this->capabilities,
            $this->directory,
        );
    }

    public function list(string $query = '', ?ApiAuthContext $auth = null, string $baseUrl = 'http://localhost:8081'): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, 5);
        // Todos aceitam vários valores menos o estado, que é uma escolha de três: todos,
        // ligados, desligados. Escolher "ligados e desligados" seria escolher todos, que já
        // é a ausência do filtro.
        $filters = [
            'deviceType' => $this->query->filterList($params, 'deviceType'),
            'supplier' => $this->query->filterList($params, 'supplier'),
            'model' => $this->query->filterList($params, 'model'),
            'license' => $this->query->filterList($params, 'license'),
            'q' => $this->query->filter($params, 'q', ''),
        ];
        $online = $this->query->onlineFilter($params);

        // A empresa e a licença viajam como pares em `license`. Este endpoint é público e
        // documentado, por isso os dois parâmetros soltos continuam a funcionar em vez de
        // serem ignorados em silêncio: uma empresa com licença dá o par, uma empresa sozinha
        // dá a empresa toda, e uma licença sozinha é uma condição independente -- não há par
        // para formar sem empresa.
        $legacyCompany = $this->query->filter($params, 'company');
        $legacyLicenseId = $this->query->filter($params, 'licenseId');
        if ($legacyCompany !== null && $legacyCompany !== 'all') {
            $filters['license'][] = $legacyLicenseId !== null && $legacyLicenseId !== 'all'
                ? $legacyCompany . ':' . $legacyLicenseId
                : $legacyCompany;
        } elseif ($legacyLicenseId !== null && $legacyLicenseId !== 'all') {
            $filters['licenseId'] = $legacyLicenseId;
        }
        $licenseScope = $auth !== null && !$auth->isAdmin() ? $auth->licenseId : null;
        $companyScope = $auth !== null && !$auth->isAdmin() ? $auth->company : null;

        // A presença não está na base de dados, e por isso entra na consulta como uma lista
        // de IMEI em vez de uma coluna -- na mesma cláusula que os outros filtros, para a
        // paginação, o total e as contagens por opção continuarem certos.
        $onlineImeis = $online === null ? [] : $this->store->onlineDeviceImeis();
        $queryFilters = $filters;
        if ($online === true) {
            $queryFilters['imeiIn'] = $onlineImeis;
        } elseif ($online === false) {
            $queryFilters['imeiNotIn'] = $onlineImeis;
        }

        $result = $this->db->whitelist->listPage($queryFilters, $page, $limit, $licenseScope, $companyScope);
        $runtimeStates = $this->store->runtimeStates(array_map(
            static fn (array $device): string => (string)($device['imei'] ?? ''),
            $result['items']
        ));
        $items = array_map(
            fn (array $device): array => $this->presentation->attachImage(
                $this->directory->overlayRuntimeState($device, $runtimeStates),
                $this->directory->modelForSupplierAndName(
                    (string)($device['supplier'] ?? ''),
                    (string)($device['model'] ?? '')
                ),
                $baseUrl
            ),
            $result['items']
        );
        $totalPages = max(1, (int)ceil(((int)$result['total']) / max(1, $limit)));

        return [
            'data' => $items,
            'pagination' => [
                'limit' => $limit,
                'page' => min(max(1, $page), $totalPages),
                'total_pages' => $totalPages,
                'total' => (int)$result['total'],
            ],
            'filters' => [
                'applied' => $filters + [
                    'online' => $online,
                    'company' => $legacyCompany,
                    'licenseId' => $legacyLicenseId,
                ],
                'available' => $result['available'],
                'counts' => $result['counts'],
            ],
            // O total e quantos deles estão ligados, sem filtro nenhum aplicado: é o que o
            // cabeçalho do modal mostra, e não muda quando se filtra.
            'summary' => $this->deviceSummary($licenseScope, $companyScope),
        ];
    }

    /**
     * @return array{total: int, online: int}
     */
    private function deviceSummary(?int $licenseScope, ?string $companyScope): array
    {
        return [
            'total' => $this->db->whitelist->countDevices([], $licenseScope, $companyScope),
            'online' => $this->db->whitelist->countDevices(
                ['imeiIn' => $this->store->onlineDeviceImeis()],
                $licenseScope,
                $companyScope
            ),
        ];
    }

    public function show(string $imei, ?ApiAuthContext $auth = null, string $baseUrl = 'http://localhost:8081'): array
    {
        $device = $this->directory->deviceSnapshot($imei);
        if (!$this->directory->canAccessDevice($imei, $auth, $device)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));
        $modelRow = $this->directory->modelForDevice($device);
        $configRows = $this->db->deviceConfigurations->allForImei($imei);
        $model = null;
        if ($modelRow !== null) {
            $model = [
                'supplier' => (string)($device['supplier'] ?? ''),
                'internalModel' => (string)($modelRow['internal_model'] ?? ''),
                'commercialName' => (string)($modelRow['commercial_name'] ?? ''),
                'deviceType' => (string)($modelRow['device_type'] ?? ''),
                // O que o modelo sabe fazer é declarado por protocolo no registry, e a
                // dashboard precisa de saber qual é para o ler em vez de o adivinhar.
                'protocol' => $protocol,
                'image' => $this->presentation->modelImage($modelRow, $baseUrl),
            ];
        }

        $device = array_diff_key($device, array_flip([
            'supplier', 'model', 'deviceType', 'protocol', 'transport', 'lastConnectionId',
        ]));
        $lifecycle = $this->configurationLifecycle($imei, $modelRow, $protocol, $configRows);

        return $this->responseCompactor->compact([
            'device' => $device,
            'model' => $model,
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($configRows),
            ],
            'configurations' => $this->configuration($imei),
            'effectiveConfigurations' => $lifecycle['effectiveConfigurations'],
            'configurationSync' => $lifecycle['configurationSync'],
            'capabilities' => $this->capabilities->deviceCapabilities($modelRow, $protocol, $configRows),
            'enabledCapabilityKeys' => $modelRow !== null
                ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
                : CapabilityCatalog::keysForProtocol($protocol),
            'linkedDevices' => $this->withGatewaySightings(
                $this->db->gatewayDeviceLinks->forDevice($imei)
            ),
        ]);
    }

    /**
     * Acrescenta o último sinal em que cada ligação foi ouvida.
     *
     * Um avistamento é sempre guardado contra o dispositivo retransmitido, e por isso as duas
     * colunas da ligação resolvem-no dos dois lados: a página de um sensor e a do seu gateway
     * leem o mesmo registo em vez de cada uma precisar da sua consulta.
     *
     * @param list<array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    private function withGatewaySightings(array $links): array
    {
        $byDevice = [];
        foreach ($links as $index => $link) {
            $relayedKey = (string)($link['linkedDeviceKey'] ?? '');
            $gatewayKey = (string)($link['gatewayDeviceKey'] ?? '');
            if ($relayedKey === '' || $gatewayKey === '') {
                continue;
            }
            $byDevice[$relayedKey] ??= $this->store->gatewaySightings($relayedKey);
            $sighting = $byDevice[$relayedKey][$gatewayKey] ?? null;
            if (is_array($sighting)) {
                $links[$index] += [
                    'rssiDbm' => isset($sighting['rssiDbm']) ? (int)$sighting['rssiDbm'] : null,
                    'signalSeenAt' => (string)($sighting['lastSeenAt'] ?? ''),
                ];
            }
        }

        return $links;
    }

    public function links(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        return ['data' => $this->db->gatewayDeviceLinks->forDevice($imei)];
    }

    public function createLink(string $imei, string $linkedImei, ?ApiAuthContext $auth = null): array
    {
        $validation = $this->validateGatewayLink($imei, $linkedImei, $auth);
        if (isset($validation['error'])) {
            return $validation;
        }
        $this->db->gatewayDeviceLinks->upsert($imei, $linkedImei);
        return ['status' => 'ok', 'gatewayDeviceKey' => $imei, 'linkedDeviceKey' => $linkedImei];
    }

    public function deleteLink(string $imei, string $linkedImei, ?ApiAuthContext $auth = null): array
    {
        $validation = $this->validateGatewayLink($imei, $linkedImei, $auth);
        if (isset($validation['error'])) {
            return $validation;
        }
        $this->db->gatewayDeviceLinks->delete($imei, $linkedImei);
        return ['status' => 'ok', 'gatewayDeviceKey' => $imei, 'linkedDeviceKey' => $linkedImei];
    }

    private function validateGatewayLink(string $imei, string $linkedImei, ?ApiAuthContext $auth): array
    {
        $gateway = $this->whitelist->getMetadata($imei);
        $linked = $this->whitelist->getMetadata($linkedImei);
        if ($gateway === null || $linked === null || !$this->directory->canAccessDevice($imei, $auth) || !$this->directory->canAccessDevice($linkedImei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        if (($gateway['deviceType'] ?? '') !== 'gateway' || !in_array($linked['deviceType'] ?? '', self::GATEWAY_LINKED_DEVICE_TYPES, true)) {
            return ['error' => ['code' => 'invalid_link', 'message' => 'A gateway can only link to a diaper sensor or a bracelet']];
        }
        if (
            (string)($gateway['company'] ?? 'null') !== (string)($linked['company'] ?? 'null')
            || (string)($gateway['licenseId'] ?? '0') !== (string)($linked['licenseId'] ?? '0')
        ) {
            return ['error' => ['code' => 'invalid_link', 'message' => 'Linked devices must belong to the same company and license']];
        }
        return ['status' => 'ok'];
    }

    public function requestFeature(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        return $this->featureRequests->requestFeature($imei, $body, $auth, $requestId);
    }

    public function commandStatus(string $id, ?ApiAuthContext $auth = null): array
    {
        return $this->featureRequests->commandStatus($id, $auth);
    }

    public function configuration(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $device = $this->directory->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel((string)($device['supplier'] ?? ($metadata['supplier'] ?? '')), (string)($device['model'] ?? ($metadata['model'] ?? ''))));
        return $this->configurationQueries->current($imei, $protocol);
    }

    public function updateConfigurations(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'not_found',
            ]);
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        if (!isset($decoded['configurations']) || !is_array($decoded['configurations'])) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_configurations_object',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'configurations object is required']];
        }

        $device = $this->directory->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel($supplier, $model));
        $modelRow = $this->directory->modelForSupplierAndName($supplier, $model);
        $update = $this->configurationUpdates->update(
            $imei,
            $decoded['configurations'],
            $supplier,
            $model,
            $protocol,
            $modelRow,
            $metadata,
            $device,
            $requestId,
        );
        if (isset($update['error'])) {
            return $update;
        }
        $results = $update['results'] ?? [];

        Logger::channel('api')->info('API device configuration processed', [
            'request_id' => $requestId,
            'imei' => $imei,
            'mode' => 'configurations',
            'result_count' => count($results),
            'config_keys' => array_keys($decoded['configurations']),
        ]);

        $snapshot = $this->show($imei, $auth);

        return [
            'status' => 'ok',
            'results' => $results,
            'configurations' => $this->configuration($imei, $auth),
            'effectiveConfigurations' => $snapshot['effectiveConfigurations'] ?? [],
            'configurationSync' => $snapshot['configurationSync'] ?? [],
        ];
    }

    public function create(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $imei = trim((string)($decoded['imei'] ?? ''));
        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        $modelRecord = $this->directory->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->directory->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
        $simNumber = trim((string)($decoded['simNumber'] ?? ''));
        $deviceId = trim((string)($decoded['deviceId'] ?? $decoded['device_id'] ?? ''));
        $company = DeviceMetadata::normalizeCompany((string)($decoded['company'] ?? 'null'));
        if ($imei === '' || $supplier === '' || $model === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($modelRecord === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model does not exist for this supplier']];
        }
        if ($this->whitelist->getMetadata($imei) !== null) {
            return ['error' => ['code' => 'device_exists', 'message' => 'Device with this IMEI already exists']];
        }
        $deviceId = $this->directory->normalizeDeviceId($imei, $supplier, $model, $deviceType, $deviceId);
        $this->whitelist->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->store->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);

        return ['status' => 'ok', 'imei' => $imei];
    }

    public function update(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        if (
            isset($decoded['configurations'])
            || isset($decoded['configs'])
            || isset($decoded['capabilities'])
        ) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'configuration_payload_not_allowed_on_metadata_endpoint',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Use /api/devices/{imei}/configurations for device configurations']];
        }

        if ($auth !== null && !$auth->isAdmin()) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'forbidden',
                'reason' => 'metadata_update_requires_admin',
            ]);
            return ['error' => ['code' => 'forbidden', 'message' => 'Forbidden']];
        }

        $newImei = trim((string)($decoded['imei'] ?? $imei));
        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        $modelRecord = $this->directory->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->directory->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
        $simNumber = trim((string)($decoded['simNumber'] ?? ''));
        $deviceId = trim((string)($decoded['deviceId'] ?? $decoded['device_id'] ?? ''));
        $company = DeviceMetadata::normalizeCompany((string)($decoded['company'] ?? 'null'));
        if ($newImei === '' || $supplier === '' || $model === '') {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_required_metadata_fields',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($modelRecord === null) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'model_not_found',
            ]);
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model does not exist for this supplier']];
        }
        if ($newImei !== $imei && $this->whitelist->getMetadata($newImei) !== null) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'new_imei' => $newImei,
                'error_code' => 'device_exists',
            ]);
            return ['error' => ['code' => 'device_exists', 'message' => 'Device with this IMEI already exists']];
        }
        // O dispositivo pode estar a sair de um cliente, a mudar de tipo ou a mudar de IMEI,
        // e cada uma dessas deixa um estado retido no tópico antigo.
        $previous = $this->whitelist->getMetadata($imei) ?? [];
        $previousCompany = DeviceMetadata::normalizeCompany((string)($previous['company'] ?? 'null'));
        $previousLicenseId = DeviceMetadata::normalizeLicenseId($previous['licenseId'] ?? 0);
        $previousDeviceType = DeviceMetadata::normalizeDeviceType((string)($previous['deviceType'] ?? 'watch'));
        if ($newImei !== $imei
            || $previousCompany !== $company
            || $previousLicenseId !== $licenseId
            || $previousDeviceType !== $deviceType
        ) {
            $this->hub->clearRetainedStatus($previousCompany, $previousLicenseId, $previousDeviceType, $imei);
        }

        $deviceId = $this->directory->normalizeDeviceId($newImei, $supplier, $model, $deviceType, $deviceId);
        if ($newImei !== $imei) {
            $this->whitelist->unregister($imei);
            $this->store->deleteDevice($imei);
        }
        $this->whitelist->register($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->store->registerDevice($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);

        Logger::channel('api')->info('API device metadata updated', [
            'request_id' => $requestId,
            'imei' => $imei,
            'new_imei' => $newImei,
            'supplier' => $supplier,
            'model' => $model,
            'license_id' => $licenseId,
            'company' => $company,
        ]);

        return ['status' => 'ok', 'imei' => $newImei];
    }

    public function patchAssociation(string $imei, string $body, ?ApiAuthContext $auth = null): array
    {
        return $this->associations->associate($imei, $body, $auth);
    }

    public function deleteAssociation(string $imei, ?ApiAuthContext $auth = null): array
    {
        return $this->associations->remove($imei, $auth);
    }

    public function delete(string $imei): array
    {
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $this->hub->clearRetainedStatus(
            DeviceMetadata::normalizeCompany((string)($metadata['company'] ?? 'null')),
            DeviceMetadata::normalizeLicenseId($metadata['licenseId'] ?? 0),
            DeviceMetadata::normalizeDeviceType((string)($metadata['deviceType'] ?? 'watch')),
            $imei
        );
        $this->whitelist->unregister($imei);
        $this->store->deleteDevice($imei);

        return ['status' => 'ok', 'imei' => $imei];
    }

    /**
     * O stream subscreve aqui para saber quando o `recent()` devolveria algo novo, em vez de
     * o voltar a ler num temporizador.
     */
    public function updates(): DeviceUpdateNotifier
    {
        return $this->store->updates();
    }

    public function recent(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        return [
            'telemetry' => $this->store->recent($imei, 'telemetry'),
            'events' => $this->store->recent($imei, 'events'),
            'commands' => $this->store->commands($imei),
        ];
    }

    /**
     * @param list<array<string,mixed>> $configRows
     * @return array{effectiveConfigurations:array<string,mixed>,configurationSync:array<string,mixed>}
     */
    private function configurationLifecycle(
        string $imei,
        ?array $model,
        string $protocol,
        array $configRows
    ): array {
        $changes = $this->db->configurationLifecycle->currentForImei($imei);
        $entries = [];
        $effective = [];
        foreach ($changes as $change) {
            $key = (string)$change['config_key'];
            $section = CapabilityCatalog::sectionForCapabilityKey($key) ?? 'settings_system';
            $operations = array_map(static fn(array $operation): array => [
                'operationId' => (string)$operation['operation_id'],
                'nativeKey' => (string)$operation['native_key'],
                'command' => (string)$operation['native_type'],
                'confirmationMode' => (string)$operation['confirmation_mode'],
                'deliveryStatus' => (string)$operation['delivery_status'],
                'error' => (string)$operation['error_code'],
                'attempts' => (int)$operation['attempts'],
                'maxAttempts' => (int)$operation['max_attempts'],
                'updatedAt' => (string)$operation['updated_at'],
            ], (array)$change['operations']);
            $nativeKey = (string)($operations[0]['nativeKey'] ?? '');
            $desiredValue = $change['desired_payload'];
            if (is_array($desiredValue)) {
                $desiredValue = $this->capabilities->normalizeCapabilityValue(
                    $protocol,
                    $key,
                    $nativeKey,
                    $desiredValue
                );
            }
            $effectiveValue = $change['effective_payload'];
            if (is_array($effectiveValue)) {
                $effectiveValue = $this->capabilities->normalizeCapabilityValue(
                    $protocol,
                    $key,
                    $nativeKey,
                    $effectiveValue
                );
                $effective[$key] = $effectiveValue;
            }
            $entries[$section][$key] = [
                'status' => (string)$change['sync_status'],
                'changeId' => (string)$change['change_id'],
                'desiredRevision' => (int)$change['desired_revision'],
                'desired' => $desiredValue,
                'effective' => $effectiveValue,
                'hasUnconfirmedChanges' => (string)$change['sync_status'] !== 'confirmed',
                'desiredUpdatedAt' => (string)$change['created_at'],
                'confirmedAt' => (string)$change['confirmed_at'],
                'operations' => $operations,
            ];
        }

        // As linhas escritas antes do ciclo de vida continuam legíveis até o próximo PATCH
        // lhes criar a primeira revisão.
        $legacyPending = $this->pendingConfiguration($model, $protocol, $configRows);
        $desired = $this->configuration($imei);
        foreach ($desired as $key => $value) {
            $section = CapabilityCatalog::sectionForCapabilityKey((string)$key) ?? 'settings_system';
            if (isset($entries[$section][$key])) {
                continue;
            }
            $pending = $legacyPending[$section][$key] ?? null;
            $status = is_array($pending) ? (string)($pending['status'] ?? 'awaiting_confirmation') : 'confirmed';
            $legacyEffective = $status === 'confirmed' || $status === 'applied' ? $value : null;
            if ($legacyEffective !== null) {
                $effective[$key] = $legacyEffective;
            }
            $entries[$section][$key] = [
                'status' => $status === 'applied' ? 'confirmed' : $status,
                'changeId' => '',
                'desiredRevision' => 0,
                'desired' => $value,
                'effective' => $legacyEffective,
                'hasUnconfirmedChanges' => $legacyEffective === null,
                'operations' => [],
            ];
        }

        $flat = [];
        foreach ($entries as $section) {
            array_push($flat, ...array_values($section));
        }
        $pendingCount = count(array_filter($flat, static fn(array $entry): bool =>
            !in_array($entry['status'], ['confirmed', 'failed'], true)
        ));
        $failedCount = count(array_filter($flat, static fn(array $entry): bool =>
            $entry['status'] === 'failed'
        ));

        return [
            'effectiveConfigurations' => $effective,
            'configurationSync' => [
                'status' => $failedCount > 0 ? 'failed' : ($pendingCount > 0 ? 'pending' : 'confirmed'),
                'hasUnconfirmedChanges' => $pendingCount > 0 || $failedCount > 0,
                'pendingCount' => $pendingCount,
                'failedCount' => $failedCount,
                'entries' => $entries,
            ],
        ];
    }

    private function pendingConfiguration(?array $model, string $protocol, array $configRows): array
    {
        $desiredCapabilities = $this->capabilities->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'desired_payload', false);
        $reportedCapabilities = $this->capabilities->deviceCapabilitiesFromPayloadKey(
            $model,
            $protocol,
            $this->configurationValueReportRows($configRows),
            'reported_payload',
            false
        );
        return $this->configurationSync->pendingEntries(
            $protocol,
            $desiredCapabilities,
            $reportedCapabilities,
            $configRows,
        );
    }

    /**
     * As confirmações de entrega ficam no `reported_payload` para a hora e a resposta nativa
     * continuarem inspeccionáveis. Não são valores de configuração reportados, e não podem
     * ser comparadas com o payload pretendido.
     *
     * @param list<array<string, mixed>> $configRows
     * @return list<array<string, mixed>>
     */
    private function configurationValueReportRows(array $configRows): array
    {
        return array_map(function (array $row): array {
            $reported = is_array($row['reported_payload'] ?? null)
                ? $row['reported_payload']
                : [];
            if ($this->isAcknowledgementOnlyConfigurationReport($reported)) {
                $row['reported_payload'] = [];
            }

            return $row;
        }, $configRows);
    }

    /**
     * @param array<string, mixed> $reported
     */
    private function isAcknowledgementOnlyConfigurationReport(array $reported): bool
    {
        if ((string)($reported['type'] ?? '') !== 'device_config') {
            return false;
        }

        $data = $reported['data'] ?? null;
        if (!is_array($data) || array_diff(array_keys($data), ['status']) !== []) {
            return false;
        }

        return in_array(strtolower(trim((string)($data['status'] ?? ''))), [
            'ok',
            'success',
            'acked',
        ], true);
    }

}
