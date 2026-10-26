<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\DevicePresentation;
use Hub\Api\Http\ModelImageUrl;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\DeviceWriteRequest;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\State\DeviceStore;
use Hub\State\DeviceUpdateNotifier;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\DeviceTypeCatalog;
use Hub\Domain\Liveness;
use Hub\Domain\ProtocolRegistry;
use Hub\Domain\VoiceDataMarker;
use Hub\Device\DeviceHubServer;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;

class DeviceService
{
    private const MAX_INLINE_VOICE_DATA_BYTES = 65536;

    private CollectionQuery $query;
    private ModelImageUrl $images;
    private CapabilityRegistry $capabilityRegistry;
    private DeviceConfigurationUpdateService $configurationUpdates;
    private DeviceConfigurationQueryService $configurationQueries;
    public readonly DeviceAssociationService $associations;
    private DeviceCapabilityPresenter $capabilities;
    private ConfigurationSyncStatus $configurationSync;
    private DeviceDirectory $directory;
    private ConfigurationLifecyclePresenter $lifecyclePresenter;
    public readonly DeviceFeatureRequestService $featureRequests;

    public function __construct(
        private DeviceStore $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ApiDataAccess $db,
    ) {
        $this->query = new CollectionQuery();
        $this->images = new ModelImageUrl();
        $this->capabilityRegistry = new CapabilityRegistry();
        $this->configurationUpdates = new DeviceConfigurationUpdateService(
            $this->store,
            $this->hub,
            $this->db,
            $this->capabilityRegistry,
        );
        $this->configurationQueries = new DeviceConfigurationQueryService(
            $this->db,
            $this->capabilityRegistry,
        );
        $this->associations = new DeviceAssociationService($this->store->runtime, $this->whitelist, $this->db, $this->hub);
        $this->capabilities = new DeviceCapabilityPresenter($this->capabilityRegistry, $this->db);
        $this->configurationSync = new ConfigurationSyncStatus();
        $this->directory = new DeviceDirectory($this->store->runtime, $this->whitelist, $this->db);
        $this->lifecyclePresenter = new ConfigurationLifecyclePresenter(
            $this->db->deviceConfigurations,
            $this->capabilities,
            $this->configurationSync,
        );
        $this->featureRequests = new DeviceFeatureRequestService(
            $this->store,
            $this->store->commands,
            $this->hub,
            $this->db,
            $this->capabilityRegistry,
            $this->capabilities,
            $this->directory,
        );
    }

    /** @return array<string, mixed> */
    public function list(string $query, ?ApiAuthContext $auth, string $baseUrl): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, CollectionPresenter::DEFAULT_LIMIT, max: 200);
        // Todos aceitam vários valores menos o estado, que é uma escolha de três.
        $filters = [
            'deviceType' => $this->query->filterList($params, 'deviceType'),
            'supplier' => $this->query->filterList($params, 'supplier'),
            'model' => $this->query->filterList($params, 'model'),
            'license' => $this->query->filterList($params, 'license'),
            'q' => $this->query->filter($params, 'q', ''),
        ];
        $online = $this->query->onlineFilter($params);

        // A empresa e a licença viajam como pares em `license`. Os dois parâmetros soltos
        // continuam a funcionar por o endpoint ser público e documentado.
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

        // A presença não é uma coluna: entra como lista de IMEI, na mesma cláusula dos outros
        // filtros, para a paginação e as contagens saírem certas. Ligados são os que se alcançam
        // agora; desligados, os que falharam; quem dorme ou espera a próxima leitura fica fora dos dois.
        $queryFilters = $filters;
        if ($online === true) {
            $queryFilters['imeiIn'] = $this->store->runtime->reachableDeviceImeis();
        } elseif ($online === false) {
            $queryFilters['imeiNotIn'] = [...$this->store->runtime->onlineDeviceImeis(), ...$this->onDemandImeis()];
        }

        $result = $this->db->whitelist->listPage($queryFilters, $page, $limit, $licenseScope, $companyScope);
        $runtimeStates = $this->store->runtime->runtimeStates(array_map(
            static fn (array $device): string => (string)($device['imei'] ?? ''),
            $result['items']
        ));
        $items = array_map(
            fn (array $device): array => ['image' => $this->modelImage(
                $this->directory->modelForSupplierAndName((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')),
                $baseUrl,
            )] + DevicePresentation::withOwner($this->directory->overlayRuntimeState($device, $runtimeStates)),
            $result['items']
        );
        $totalPages = max(1, (int)ceil(((int)$result['total']) / max(1, $limit)));

        return [
            'data' => $items,
            'pagination' => [
                'limit' => $limit,
                'page' => min(max(1, $page), $totalPages),
                'totalPages' => $totalPages,
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
                ['imeiIn' => $this->store->runtime->reachableDeviceImeis()],
                $licenseScope,
                $companyScope
            ),
        ];
    }

    /**
     * Os que só se ligam para medir: nunca estão desligados.
     *
     * @return list<string>
     */
    private function onDemandImeis(): array
    {
        $onDemand = array_filter(
            $this->whitelist->all(),
            static fn (DeviceMetadata $device): bool => Liveness::isOnDemand(ProtocolRegistry::forModel($device->supplier, $device->model)),
        );

        return array_map(strval(...), array_keys($onDemand));
    }

    /** @return array<string, mixed> */
    public function show(string $imei, ?ApiAuthContext $auth, string $baseUrl): array
    {
        $device = $this->directory->deviceSnapshot($imei);
        if (!$this->directory->canAccessDevice($imei, $auth, $device)) {
            return ApiError::deviceNotFound()->toArray();
        }
        $protocol = (string)($device['protocol'] ?? ProtocolRegistry::forModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));
        $modelRow = $this->directory->modelForSupplierAndName((string)($device['supplier'] ?? ''), (string)($device['model'] ?? ''));
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
                'image' => $this->modelImage($modelRow, $baseUrl),
            ];
        }

        $device = array_diff_key($device, array_flip([
            'supplier', 'model', 'deviceType', 'protocol', 'transport', 'lastConnectionId',
        ]));
        // Os áudios grandes das configurações ficam fora do detalhe.
        return (new VoiceDataMarker(self::MAX_INLINE_VOICE_DATA_BYTES))->mark([
            'device' => DevicePresentation::withOwner($device),
            'model' => $model,
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($configRows),
            ],
            ...$this->configurationState($imei, $modelRow, $protocol, $configRows),
            'capabilities' => $this->capabilities->deviceCapabilities($modelRow, $protocol, $configRows),
            'enabledCapabilityKeys' => $modelRow !== null
                ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
                : CapabilityCatalog::keysForProtocol($protocol),
            'linkedDevices' => $this->withGatewaySightings(
                $this->db->gatewayDeviceLinks->forDevice($imei),
                $baseUrl,
            ),
        ]);
    }

    /** @param array<string, mixed>|null $modelRow */
    private function modelImage(?array $modelRow, string $baseUrl): ?string
    {
        return $modelRow === null ? null : $this->images->resolve((string)($modelRow['image_path'] ?? ''), $baseUrl);
    }

    /**
     * O último sinal de cada ligação, a fotografia do modelo, e quando o par dispararia com o
     * gateway a calibrar. O avistamento é guardado contra o dispositivo
     * retransmitido, e as duas colunas resolvem-no dos dois lados.
     *
     * @param list<array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    private function withGatewaySightings(array $links, string $baseUrl): array
    {
        $byDevice = [];
        $candidates = [];
        foreach ($links as $index => $link) {
            $relayedKey = (string)($link['linkedDeviceKey'] ?? '');
            $gatewayKey = (string)($link['gatewayDeviceKey'] ?? '');
            if ($relayedKey === '' || $gatewayKey === '') {
                continue;
            }
            $byDevice[$relayedKey] ??= $this->store->runtime->gatewaySightings($relayedKey);
            $sighting = $byDevice[$relayedKey][$gatewayKey] ?? null;
            if (is_array($sighting)) {
                $links[$index] += [
                    'rssiDbm' => isset($sighting['rssiDbm']) ? (int)$sighting['rssiDbm'] : null,
                    'signalSeenAt' => (string)($sighting['lastSeenAt'] ?? ''),
                ];
            }
            $candidates[$relayedKey] ??= $this->store->runtime->proximityAlarmCandidates($relayedKey);
            $links[$index]['proximityAlarmCandidateAt'] = $candidates[$relayedKey][$gatewayKey] ?? null;
            $links[$index]['image'] = $this->modelImage(
                $this->directory->modelForSupplierAndName((string)($link['supplier'] ?? ''), (string)($link['model'] ?? '')),
                $baseUrl,
            );
        }

        return $links;
    }

    /** @return array<string, mixed> */
    public function links(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ApiError::deviceNotFound()->toArray();
        }
        return ['data' => $this->db->gatewayDeviceLinks->forDevice($imei)];
    }

    /** @return array<string, mixed> */
    public function createLink(string $imei, string $linkedImei, ?ApiAuthContext $auth = null): array
    {
        $validation = $this->validateGatewayLink($imei, $linkedImei, $auth);
        if (isset($validation['error'])) {
            return $validation;
        }
        $this->db->gatewayDeviceLinks->upsert($imei, $linkedImei);
        return ['status' => 'ok', 'gatewayDeviceKey' => $imei, 'linkedDeviceKey' => $linkedImei];
    }

    /** @return array<string, mixed> */
    public function deleteLink(string $imei, string $linkedImei, ?ApiAuthContext $auth = null): array
    {
        $validation = $this->validateGatewayLink($imei, $linkedImei, $auth);
        if (isset($validation['error'])) {
            return $validation;
        }
        $this->db->gatewayDeviceLinks->delete($imei, $linkedImei);
        return ['status' => 'ok', 'gatewayDeviceKey' => $imei, 'linkedDeviceKey' => $linkedImei];
    }

    /** @return array<string, mixed> */
    private function validateGatewayLink(string $imei, string $linkedImei, ?ApiAuthContext $auth): array
    {
        $gateway = $this->whitelist->getMetadata($imei);
        $linked = $this->whitelist->getMetadata($linkedImei);
        if ($gateway === null || $linked === null || !$this->directory->canAccessDevice($imei, $auth) || !$this->directory->canAccessDevice($linkedImei, $auth)) {
            return ApiError::deviceNotFound()->toArray();
        }
        if ($gateway->deviceType !== 'gateway' || !in_array($linked->deviceType, DeviceTypeCatalog::linkedToGateway(), true)) {
            return ApiError::invalidLink('A gateway can only link to a diaper sensor or a bracelet')->toArray();
        }
        if ($gateway->company !== $linked->company || $gateway->licenseId !== $linked->licenseId) {
            return ApiError::invalidLink('Linked devices must belong to the same company and license')->toArray();
        }
        return ['status' => 'ok'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateConfigurations(string $imei, array $payload, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'not_found',
            ]);
            return ApiError::deviceNotFound()->toArray();
        }

        if (!isset($payload['configurations']) || !is_array($payload['configurations'])) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_configurations_object',
            ]);
            return ApiError::invalidField('configurations', 'configurations object is required')->toArray();
        }

        $identity = $this->directory->identify($imei);
        $update = $this->configurationUpdates->update(
            $imei,
            $payload['configurations'],
            $identity->supplier,
            $identity->model,
            $identity->protocol,
            $identity->modelRow,
            $identity->metadata?->toArray() ?? [],
            $identity->device,
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
            'config_keys' => array_keys($payload['configurations']),
        ]);

        return (new VoiceDataMarker(self::MAX_INLINE_VOICE_DATA_BYTES))->mark([
            'status' => 'ok',
            'results' => $results,
            ...$this->configurationState(
                $imei,
                $identity->modelRow,
                $identity->protocol,
                $this->db->deviceConfigurations->allForImei($imei),
            ),
        ]);
    }

    /**
     * O desejado, o confirmado e a convergência entre os dois.
     *
     * @param array<string, mixed>|null $modelRow
     * @param list<array<string, mixed>> $configRows
     * @return array{configurations: array<string, mixed>, effectiveConfigurations: mixed, configurationSync: mixed}
     */
    private function configurationState(string $imei, ?array $modelRow, string $protocol, array $configRows): array
    {
        $configurations = $this->configurationQueries->current($imei, $protocol, $configRows);
        $lifecycle = $this->lifecyclePresenter->present($imei, $modelRow, $protocol, $configRows, $configurations);

        return [
            'configurations' => $configurations,
            'effectiveConfigurations' => $lifecycle['effectiveConfigurations'],
            'configurationSync' => $lifecycle['configurationSync'],
        ];
    }

    /** @return array<string, mixed> */
    public function create(DeviceWriteRequest $request, ?ApiAuthContext $auth = null): array
    {
        $imei = trim((string)$request->imei);
        $supplier = trim($request->supplier);
        $model = trim($request->model);
        $modelRecord = $this->directory->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $request->deviceType));
        $licenseId = $request->licenseId ?? 0;
        $simNumber = trim($request->simNumber);
        $deviceId = trim($request->deviceId);
        $company = DeviceMetadata::normalizeCompany($request->company);
        $ownerError = self::ownerError($request);
        if ($ownerError !== null) {
            return $ownerError->toArray();
        }
        if ($auth !== null && !$auth->canAccessTenant($company, $licenseId)) {
            return ApiError::forbidden()->toArray();
        }
        if ($modelRecord === null) {
            return ApiError::modelNotFoundForSupplier()->toArray();
        }
        if ($this->whitelist->getMetadata($imei) !== null) {
            return ApiError::deviceExists()->toArray();
        }
        $deviceId = $this->directory->normalizeDeviceId($imei, $supplier, $model, $deviceType, $deviceId);
        // Redis primeiro, inventário a seguir: uma entrada a mais na projecção é invisível, e a ordem
        // contrária deixaria na lista um dispositivo que a dashboard não conhece.
        $this->store->runtime->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        try {
            $this->whitelist->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        } catch (\Throwable $e) {
            // Aqui a compensação é segura porque acabámos de confirmar que o IMEI não existia:
            // não há estado anterior para apagar sem querer.
            $this->store->deleteDevice($imei);
            throw $e;
        }

        return ['status' => 'ok', 'imei' => $imei];
    }

    /**
     * @param bool $carriesConfigurations o corpo trouxe `configurations`, que têm rota própria
     * @return array<string, mixed>
     */
    public function update(
        string $imei,
        DeviceWriteRequest $request,
        ?ApiAuthContext $auth = null,
        string $requestId = '',
        bool $carriesConfigurations = false,
    ): array {
        $audit = new DeviceWriteAudit($imei, $requestId);

        if ($carriesConfigurations) {
            return $audit->reject(
                ApiError::invalidField('configurations', 'Use /api/devices/{imei}/configurations for device configurations'),
                'configuration_payload_not_allowed_on_metadata_endpoint',
            );
        }

        if ($auth !== null && !$auth->isAdmin()) {
            return $audit->reject(ApiError::forbidden(), 'metadata_update_requires_admin');
        }

        // Ausente é o do endereço; vazio é uma recusa. Daí o campo ser anulável e o
        // `NotBlank` só valer no grupo `create`.
        $newImei = trim($request->imei ?? $imei);
        $supplier = trim($request->supplier);
        $model = trim($request->model);
        $modelRecord = $this->directory->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $request->deviceType));
        $licenseId = $request->licenseId ?? 0;
        $simNumber = trim($request->simNumber);
        $deviceId = trim($request->deviceId);
        $company = DeviceMetadata::normalizeCompany($request->company);
        $ownerError = self::ownerError($request);
        if ($ownerError !== null) {
            return $audit->reject($ownerError, 'incomplete_owner');
        }
        if ($newImei === '') {
            return $audit->reject(
                ApiError::invalidField('imei', 'imei, supplier, and model are required'),
                'missing_required_metadata_fields',
            );
        }
        if ($modelRecord === null) {
            return $audit->reject(ApiError::modelNotFoundForSupplier());
        }
        if ($newImei !== $imei && $this->whitelist->getMetadata($newImei) !== null) {
            return $audit->reject(ApiError::deviceExists(), extra: ['new_imei' => $newImei]);
        }
        // O dispositivo pode estar a sair de um cliente, a mudar de tipo ou a mudar de IMEI,
        // e cada uma dessas deixa um estado retido no tópico antigo.
        $previous = $this->whitelist->getMetadata($imei);
        $previousCompany = $previous?->company ?? 'null';
        $previousLicenseId = $previous?->licenseId ?? 0;
        $previousDeviceType = $previous?->deviceType ?? 'watch';
        if (
            $newImei !== $imei
            || $previousCompany !== $company
            || $previousLicenseId !== $licenseId
            || $previousDeviceType !== $deviceType
        ) {
            $this->hub->clearRetainedStatus($previousCompany, $previousLicenseId, $previousDeviceType, $imei);
            $this->store->runtime->forgetActiveAlarms($imei);
        }

        $deviceId = $this->directory->normalizeDeviceId($newImei, $supplier, $model, $deviceType, $deviceId);
        // Uma falha a meio deixa no máximo uma entrada a mais no Redis, nunca um dispositivo sem
        // inventário; o `ON UPDATE CASCADE` leva as ligações de gateway e a planta do radar.
        if ($newImei !== $imei) {
            $this->whitelist->rename($imei, $newImei);
        }
        $this->store->runtime->registerDevice($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->whitelist->register($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        if ($newImei !== $imei) {
            $this->store->deleteDevice($imei);
        }

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

    /** A empresa e a licença vão juntas: as duas, ou nenhuma. Dentro do hub, nenhuma é `'null'` e `0`. */
    private static function ownerError(DeviceWriteRequest $request): ?ApiError
    {
        if ($request->company === null && $request->licenseId !== null) {
            return ApiError::incompleteOwner('company');
        }
        if ($request->company !== null && $request->licenseId === null) {
            return ApiError::incompleteOwner('licenseId');
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function delete(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ApiError::deviceNotFound()->toArray();
        }

        $metadata = $this->whitelist->getMetadata($imei);
        // Apagar o que não existe diz que não existe: um `ok` dava a quem errou o IMEI a
        // mesma resposta de quem acertou.
        if ($metadata === null) {
            return ApiError::deviceNotFound()->toArray();
        }
        $this->hub->clearRetainedStatus(
            $metadata->company,
            $metadata->licenseId,
            $metadata->deviceType,
            $imei
        );
        // Ordem inversa do registo: inventário primeiro, projecção a seguir. Uma falha no
        // Redis deixa uma entrada órfã que nada lista.
        $this->whitelist->unregister($imei);
        $this->store->deleteDevice($imei);
        $this->hub->disconnect($imei);

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

    /**
     * O histórico recente; o `$since` é o cursor do cliente por lista, e vazio devolve tudo. Os
     * comandos vão sempre inteiros porque mudam de estado.
     *
     * @param array<string, int> $since
     * @return array<string, mixed>
     */
    public function recent(string $imei, ?ApiAuthContext $auth = null, array $since = []): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ApiError::deviceNotFound()->toArray();
        }

        $telemetrySince = max(0, (int)($since['telemetry'] ?? 0));
        $eventsSince = max(0, (int)($since['events'] ?? 0));
        $connectionsSince = max(0, (int)($since['connections'] ?? 0));

        return [
            'telemetry' => self::withObjectData($this->store->events->recent($imei, 'telemetry', $telemetrySince)),
            'latestTelemetry' => self::withObjectData($this->store->events->latestOfEachType($imei, 'telemetry')),
            'events' => self::withObjectData($this->store->events->recent($imei, 'events', $eventsSince)),
            'connections' => $this->store->events->recent($imei, 'connections', $connectionsSince),
            'commands' => $this->store->commands->commands($imei),
            'cursor' => [
                'telemetry' => $this->store->events->latestSequence($imei, 'telemetry'),
                'events' => $this->store->events->latestSequence($imei, 'events'),
                'connections' => $this->store->events->latestSequence($imei, 'connections'),
            ],
            // Quantas entradas o histórico guarda, para o cliente aparar a lista ao juntar
            // uma diferença sem ter de adivinhar o limite do servidor.
            'limit' => $this->store->historyLimit(),
        ];
    }

    /**
     * O `data` sai sempre como objecto, como no MQTT: vazio, o histórico lido do Redis volta como lista.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function withObjectData(array $rows): array
    {
        foreach ($rows as &$row) {
            if (($row['data'] ?? null) === []) {
                $row['data'] = new \stdClass();
            }
        }
        unset($row);

        return $rows;
    }
}
