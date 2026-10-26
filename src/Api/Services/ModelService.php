<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionColumns;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\ModelImageUrl;
use Hub\Api\Request\ModelWriteRequest;
use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\ProtocolRegistry;
use Hub\Domain\SupplierCapabilityTemplate;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;

class ModelService
{
    private CollectionPresenter $presenter;
    private ModelImageUrl $imageUrl;
    private ModelImageStore $images;

    public function __construct(private ApiDataAccess $db, private ?Whitelist $whitelist = null)
    {
        $this->presenter = new CollectionPresenter();
        $this->imageUrl = new ModelImageUrl();
        $this->images = new ModelImageStore();
    }

    /** @return array<string, mixed> */
    public function list(string $query, string $baseUrl): array
    {
        $models = $this->presentRows($this->db->models->all(), $baseUrl);

        return $this->presenter->present(
            $models,
            new CollectionColumns(
                sortable: ['supplier', 'internalModel', 'commercialName', 'deviceType', 'protocol'],
                writable: ModelWriteRequest::class,
                textFilters: ['model' => ['internalModel', 'commercialName']],
                fixedOptions: [
                    'supplier' => self::valuesOf($models, 'supplier'),
                    'protocol' => self::valuesOf($models, 'protocol'),
                    // Do catálogo e não das linhas: um tipo ainda sem modelos sai com zero.
                    'deviceType' => CapabilityCatalog::deviceTypes(),
                ],
                extra: ['id'],
            ),
            $query,
        );
    }

    /**
     * Os fornecedores estão definidos em código, e por isso esta colecção é só de leitura.
     *
     * @return array<string, mixed>
     */
    public function suppliers(string $query): array
    {
        return $this->presenter->present(
            array_map(static fn (array $row): array => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'modelCount' => (int)$row['model_count'],
                'createdAt' => (string)$row['created_at'],
                'updatedAt' => (string)$row['updated_at'],
            ], $this->db->suppliers->all()),
            new CollectionColumns(sortable: ['name', 'modelCount'], textFilters: ['name' => 'name'], extra: ['id']),
            $query,
        );
    }

    /**
     * Os valores distintos de uma coluna, por ordem natural.
     *
     * @param list<array<string, mixed>> $models
     * @return list<string>
     */
    private static function valuesOf(array $models, string $field): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn (array $model): string => trim((string)($model[$field] ?? '')), $models),
            static fn (string $value): bool => $value !== '',
        )));
        usort($values, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $values;
    }

    /**
     * As linhas como a listagem as devolve. O protocolo e a matriz de capacidades não estão
     * na tabela dos modelos, e é aqui que se juntam.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentRows(array $rows, string $baseUrl): array
    {
        $catalogByType = [];
        $enabledByModel = $this->db->modelCapabilities->enabledFeaturesForModelIds(array_map(
            static fn (array $model): int => (int)($model['id'] ?? 0),
            $rows
        ));

        return array_map(function (array $model) use ($enabledByModel, &$catalogByType, $baseUrl): array {
            $presented = $this->modelRow($model, $baseUrl);
            $deviceType = $presented['deviceType'];
            $catalogByType[$deviceType] ??= $this->db->genericCapabilities->all($deviceType);

            return $presented + [
                'capabilities' => CapabilityCatalog::buildCapabilityMatrix($catalogByType[$deviceType], $enabledByModel[$presented['id']] ?? []),
            ];
        }, $rows);
    }

    /**
     * Uma linha de `ModelRepository::all()` como a API a mostra.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, supplierId: int, supplier: string, internalModel: string, commercialName: string, deviceType: string, protocol: string, image: ?string}
     */
    private function modelRow(array $row, string $baseUrl): array
    {
        $supplier = (string)$row['supplier'];
        $internalModel = (string)$row['internal_model'];

        return [
            'id' => (int)$row['id'],
            'supplierId' => (int)$row['supplier_id'],
            'supplier' => $supplier,
            'internalModel' => $internalModel,
            'commercialName' => (string)$row['commercial_name'],
            'deviceType' => DeviceMetadata::normalizeDeviceType((string)$row['device_type']),
            'protocol' => ProtocolRegistry::forModel($supplier, $internalModel),
            'image' => $this->imageUrl->resolve((string)$row['image_path'], $baseUrl),
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $groups = [];
        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $groups[$deviceType] = [
                'deviceType' => $deviceType,
                'suppliers' => [],
            ];
        }

        foreach ($this->db->models->supplierDeviceTypes() as $row) {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($row['device_type'] ?? 'watch'));
            if (!isset($groups[$deviceType])) {
                continue;
            }

            $groups[$deviceType]['suppliers'][] = [
                'id' => (int)($row['supplier_id'] ?? 0),
                'name' => (string)($row['supplier'] ?? ''),
            ];
        }

        return [
            'data' => array_values($groups),
        ];
    }

    /** @return array<string, mixed> */
    public function deviceTypeSuppliersModels(string $baseUrl): array
    {
        $groups = [];
        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $groups[$deviceType] = [
                'deviceType' => $deviceType,
                'suppliers' => [],
            ];
        }

        $modelsByDeviceTypeAndSupplier = [];
        foreach ($this->db->models->all() as $row) {
            $model = $this->modelRow($row, $baseUrl);
            $modelsByDeviceTypeAndSupplier[$model['deviceType']][$model['supplier']][] = $model;
        }

        foreach ($groups as $deviceType => &$group) {
            $suppliersForDeviceType = $modelsByDeviceTypeAndSupplier[$deviceType] ?? [];
            foreach ($suppliersForDeviceType as $supplierName => $models) {
                $firstModel = $models[0] ?? [];
                $group['suppliers'][] = [
                    'id' => (int)($firstModel['supplierId'] ?? 0),
                    'name' => $supplierName,
                    'models' => $models,
                ];
            }
        }
        unset($group);

        return [
            'data' => array_values($groups),
        ];
    }

    /** @return array<string, mixed> */
    public function show(int $id, string $baseUrl): array
    {
        $entry = $this->db->models->findById($id);
        if ($entry === null) {
            return ApiError::modelNotFound()->toArray();
        }

        $model = $this->modelRow($entry, $baseUrl);
        $deviceType = $model['deviceType'];
        $catalog = $this->db->genericCapabilities->all($deviceType);
        $requestableCapabilityKeys = $this->requestableTelemetryKeys($model['protocol'], $model['supplier'], $deviceType);
        $effectiveRequestable = array_values(array_intersect(
            $this->db->modelCapabilities->requestableTelemetryFeaturesForModelId($id),
            $requestableCapabilityKeys
        ));

        return $model + [
            'capabilities' => CapabilityCatalog::buildCapabilityMatrix(
                $catalog,
                $this->db->modelCapabilities->enabledFeaturesForModelId($id)
            ),
            'requestableCapabilityKeys' => $requestableCapabilityKeys,
            'requestableCapabilities' => $effectiveRequestable,
        ];
    }

    /** @return array<string, mixed> */
    public function template(string $query = ''): array
    {
        parse_str($query, $params);
        $supplierId = (int)($params['supplierId'] ?? 0);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($params['deviceType'] ?? 'watch'));
        if ($supplierId <= 0) {
            return ApiError::invalidField('supplierId', 'supplierId is required')->toArray();
        }

        $supplier = $this->db->suppliers->findById($supplierId);
        if ($supplier === null) {
            return ApiError::supplierNotFound()->toArray();
        }

        $supplierName = (string)($supplier['name'] ?? '');
        $protocol = ProtocolRegistry::forSupplier($supplierName, $deviceType);
        if ($protocol === '') {
            return ApiError::unknownProtocol('Could not determine protocol for this supplier')->toArray();
        }

        $catalog = $this->db->genericCapabilities->all($deviceType);
        $enabledKeys = SupplierCapabilityTemplate::keysForProtocol($protocol, $deviceType);

        return [
            'supplierId' => $supplierId,
            'supplier' => $supplierName,
            'deviceType' => $deviceType,
            'protocol' => $protocol,
            'enabledCapabilities' => $enabledKeys,
            'requestableCapabilityKeys' => $this->requestableTelemetryKeys(
                $protocol,
                $supplierName,
                $deviceType
            ),
            'capabilities' => CapabilityCatalog::buildCapabilityMatrix($catalog, $enabledKeys),
        ];
    }

    /** @return array<string, mixed> */
    public function create(ModelWriteRequest $request, mixed $imageUpload = null): array
    {
        $fields = $this->modelFields($request, 'create');
        if (isset($fields['error'])) {
            return $fields;
        }
        $supplierId = (int)$fields['supplier_id'];
        $internalModel = (string)$fields['internal_model'];
        $commercialName = (string)$fields['commercial_name'];
        $deviceType = (string)$fields['device_type'];
        $enabledCapabilities = $fields['enabled_capabilities'];
        $requestableCapabilities = $fields['requestable_capabilities'];

        // O par repetido responde 409, e não deixa a excepção da chave única virar um 500.
        // Antes do `storeModelImage()` para não deixar a imagem em disco sem modelo a que pertencer.
        if ($this->db->models->exists($supplierId, $internalModel)) {
            return ApiError::modelExists()->toArray();
        }

        $imagePath = $this->images->store($imageUpload);
        if (is_array($imagePath)) {
            return $imagePath;
        }
        $this->db->models->add($supplierId, $internalModel, $commercialName, $deviceType, $imagePath);
        $supplier = $this->db->suppliers->findById($supplierId);
        $stored = is_array($supplier) ? $this->db->models->find((string)$supplier['name'], $internalModel) : null;
        if (is_array($stored)) {
            $this->db->modelCapabilities->replaceForModelId((int)$stored['id'], $enabledCapabilities);
            if (is_array($requestableCapabilities)) {
                $this->db->modelCapabilities->replaceTelemetryRequestabilityForModelId(
                    (int)$stored['id'],
                    $requestableCapabilities
                );
            }
        }
        return ['status' => 'ok'];
    }

    /** @return array<string, mixed> */
    public function update(int $id, ModelWriteRequest $request, mixed $imageUpload = null, string $baseUrl = ''): array
    {
        $current = $this->db->models->findById($id);
        if ($current === null) {
            return ApiError::modelNotFound()->toArray();
        }

        $fields = $this->modelFields($request, 'update', $id);
        if (isset($fields['error'])) {
            return $fields;
        }
        $supplierId = (int)$fields['supplier_id'];
        $internalModel = (string)$fields['internal_model'];
        $commercialName = (string)$fields['commercial_name'];
        $deviceType = (string)$fields['device_type'];
        $enabledCapabilities = $fields['enabled_capabilities'];
        $requestableCapabilities = $fields['requestable_capabilities'];
        if ($this->db->models->existsForDifferentId($id, $supplierId, $internalModel)) {
            return ApiError::modelExists()->toArray();
        }

        $imagePath = $this->images->store($imageUpload);
        if (is_array($imagePath)) {
            return $imagePath;
        }

        $this->db->models->update($id, $supplierId, $internalModel, $commercialName, $deviceType, $imagePath);
        $this->whitelist?->reload();
        $this->db->modelCapabilities->replaceForModelId($id, $enabledCapabilities);
        if (is_array($requestableCapabilities)) {
            $this->db->modelCapabilities->replaceTelemetryRequestabilityForModelId($id, $requestableCapabilities);
        }
        if (is_string($imagePath)) {
            $this->images->delete((string)($current['image_path'] ?? ''));
        }

        return $this->show($id, $baseUrl);
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        $model = $this->db->models->findById($id);
        if (!is_array($model)) {
            return ApiError::modelNotFound()->toArray();
        }
        $devices = $this->db->whitelist->countForModel($id);
        if ($devices > 0) {
            return ApiError::modelInUse($devices)->toArray();
        }

        $this->db->models->delete($id);
        $this->images->delete((string)($model['image_path'] ?? ''));

        return ['status' => 'ok'];
    }

    /** @return array<string, mixed> */
    private function modelFields(ModelWriteRequest $request, string $mode, ?int $modelId = null): array
    {
        $supplierId = $request->supplierId;
        $internalModel = trim($request->internalModel);
        $commercialName = trim($request->commercialName);
        $deviceType = DeviceMetadata::normalizeDeviceType($request->deviceType);
        $supplier = $this->db->suppliers->findById($supplierId);
        if ($supplier === null) {
            return ApiError::supplierNotFound()->toArray();
        }

        $supplierName = (string)($supplier['name'] ?? '');
        $protocol = ProtocolRegistry::forSupplier($supplierName, $deviceType);
        if ($protocol === '') {
            return ApiError::unknownProtocol('Could not determine protocol for this supplier')->toArray();
        }

        $defaultFeatures = SupplierCapabilityTemplate::keysForProtocol($protocol, $deviceType);
        $defaultFeatureSet = array_flip($defaultFeatures);
        $hasEnabledCapabilitiesSelection = $request->choseCapabilities();
        $enabledCapabilities = $this->featureValues($request->capabilities);
        if (!$hasEnabledCapabilitiesSelection) {
            if ($mode === 'update' && $modelId !== null) {
                $current = $this->db->models->findById($modelId);
                $sameSupplier = (int)($current['supplier_id'] ?? 0) === $supplierId;
                $sameDeviceType = DeviceMetadata::normalizeDeviceType((string)($current['device_type'] ?? 'watch')) === $deviceType;
                $enabledCapabilities = $sameSupplier && $sameDeviceType
                    ? array_values(array_filter(
                        $this->db->modelCapabilities->enabledFeaturesForModelId($modelId),
                        static fn(string $feature): bool => isset($defaultFeatureSet[$feature]),
                    ))
                    : $defaultFeatures;
            } else {
                $enabledCapabilities = $defaultFeatures;
            }
        } else {
            $unsupported = array_values(array_filter($enabledCapabilities, static fn(string $f): bool => !isset($defaultFeatureSet[$f])));
            if ($unsupported !== []) {
                return ApiError::unsupportedCapability()->toArray();
            }
        }

        $hasRequestableCapabilitiesSelection = $request->choseRequestableCapabilities();
        $requestableCapabilities = $this->featureValues($request->requestableCapabilities);
        if ($hasRequestableCapabilitiesSelection) {
            $enabledSet = array_fill_keys($enabledCapabilities, true);
            $requestableCatalog = array_fill_keys(
                $this->requestableTelemetryKeys($protocol, $supplierName, $deviceType),
                true
            );
            $invalid = array_values(array_filter(
                $requestableCapabilities,
                static fn(string $feature): bool =>
                    !isset($enabledSet[$feature]) || !isset($requestableCatalog[$feature])
            ));
            if ($invalid !== []) {
                return ApiError::invalidRequestableCapability()->toArray();
            }
        }

        return [
            'supplier_id' => $supplierId,
            'internal_model' => $internalModel,
            'commercial_name' => $commercialName,
            'device_type' => $deviceType,
            'enabled_capabilities' => $enabledCapabilities,
            'requestable_capabilities' => $hasRequestableCapabilitiesSelection ? $requestableCapabilities : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function featureValues(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $feature = trim((string)$item);
            if ($feature === '') {
                continue;
            }
            $normalized[$feature] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @return list<string>
     */
    private function requestableTelemetryKeys(string $protocol, string $supplier, string $deviceType): array
    {
        $catalogRequestable = [];
        foreach ($this->db->genericCapabilities->all($deviceType) as $capability) {
            if (!empty($capability['is_telemetry']) && !empty($capability['is_requestable'])) {
                $catalogRequestable[(string)$capability['capability_key']] = true;
            }
        }
        $supplierSupported = array_fill_keys(
            SupplierCapabilityTemplate::keysForProtocol(ProtocolRegistry::forSupplier($supplier, $deviceType), $deviceType),
            true
        );

        $requestable = [];
        foreach (DeviceCommandCatalog::commandsForProtocol($protocol) as $command) {
            if ((string)($command['kind'] ?? '') !== 'request') {
                continue;
            }
            $feature = trim((string)($command['feature'] ?? ''));
            if (
                $feature !== ''
                && isset($catalogRequestable[$feature])
                && isset($supplierSupported[$feature])
            ) {
                $requestable[$feature] = true;
            }
        }

        return array_keys($requestable);
    }
}
