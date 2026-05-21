<?php

declare(strict_types=1);

namespace Tests\Contract\Normalization;

use App\Http\OpenApiSpec;
use PHPUnit\Framework\TestCase;

final class FeatureContractsMatrixContractTest extends TestCase
{
    public function testFeatureContractsCoverAllFeatureModelCombinations(): void
    {
        $matrix = $this->readJson('tests/fixtures/contracts/feature_support_matrix.json');
        $contracts = $this->readJson('config/feature_contracts.json');

        $featureContracts = $contracts['features'] ?? [];

        foreach (($matrix['features'] ?? []) as $feature => $entry) {
            self::assertArrayHasKey($feature, $featureContracts, "Missing feature contract for {$feature}");
            $supportedModels = $featureContracts[$feature]['supportedModels'] ?? [];

            foreach (array_keys($entry['models'] ?? []) as $model) {
                self::assertContains(
                    $model,
                    $supportedModels,
                    "Feature {$feature} is present in support matrix for {$model} but missing in feature contract"
                );
            }
        }
    }

    public function testPassiveNativeTypesHaveNormalizerMapping(): void
    {
        $matrix = $this->readJson('tests/fixtures/contracts/feature_support_matrix.json');
        $normalizers = $this->readJson('config/native_normalizer_mappings.json');
        $contracts = $this->readJson('config/feature_contracts.json');

        $featureContracts = $contracts['features'] ?? [];

        foreach (($matrix['models'] ?? []) as $model) {
            $rows = $normalizers['models'][$model] ?? [];
            $indexed = [];
            foreach ($rows as $row) {
                $indexed[(string)($row['nativeType'] ?? '')] = $row;
            }

            foreach (($matrix['features'] ?? []) as $feature => $entry) {
                $passive = $entry['models'][$model]['passive'] ?? [];
                foreach ($passive as $nativeType) {
                    self::assertArrayHasKey(
                        $nativeType,
                        $indexed,
                        "Missing native normalizer mapping for {$model} {$nativeType}"
                    );

                    $mappedFeature = $indexed[$nativeType]['feature'] ?? null;
                    self::assertNotNull(
                        $mappedFeature,
                        "Native mapping {$model} {$nativeType} must point to a primary feature"
                    );
                    self::assertArrayHasKey(
                        $mappedFeature,
                        $featureContracts,
                        "Native mapping {$model} {$nativeType} references unknown feature {$mappedFeature}"
                    );
                }
            }
        }
    }

    public function testOpenApiExposesFeaturePayloadSchemas(): void
    {
        $spec = OpenApiSpec::get();
        $schemas = $spec['components']['schemas'] ?? [];

        self::assertArrayHasKey('FeaturePayload', $schemas);
        self::assertArrayHasKey('FeaturePayloadCatalog', $schemas);
        self::assertArrayHasKey('HeartRateFeaturePayload', $schemas);
        self::assertArrayHasKey('LocationFeaturePayload', $schemas);
        self::assertArrayHasKey('DeviceConfigFeaturePayload', $schemas);
    }

    public function testOpenApiExposesLatestFeaturePayloadPath(): void
    {
        $spec = OpenApiSpec::get();
        $paths = $spec['paths'] ?? [];

        self::assertArrayHasKey('/devices/{imei}/features/{feature}/latest', $paths);

        $get = $paths['/devices/{imei}/features/{feature}/latest']['get'] ?? [];
        self::assertIsArray($get);

        $responses = $get['responses'] ?? [];
        self::assertSame(
            '#/components/schemas/FeaturePayloadResponse',
            $responses['200']['content']['application/json']['schema']['$ref'] ?? null
        );
    }

    public function testOpenApiExposesFeatureMeasurePath(): void
    {
        $spec = OpenApiSpec::get();
        $paths = $spec['paths'] ?? [];

        self::assertArrayHasKey('/devices/{imei}/features/{feature}/measure', $paths);

        $post = $paths['/devices/{imei}/features/{feature}/measure']['post'] ?? [];
        self::assertIsArray($post);

        $responses = $post['responses'] ?? [];
        self::assertSame(
            '#/components/schemas/FeatureMeasureResponse',
            $responses['200']['content']['application/json']['schema']['$ref'] ?? null
        );

        $parameterNames = array_map(
            static fn(array $param): string => (string)($param['name'] ?? ''),
            $post['parameters'] ?? []
        );
        self::assertContains('wait', $parameterNames);
        self::assertContains('timeoutMs', $parameterNames);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $absolutePath = dirname(__DIR__, 3) . '/' . ltrim($path, '/');
        self::assertFileExists($absolutePath);

        $decoded = json_decode((string)file_get_contents($absolutePath), true);
        self::assertIsArray($decoded, "Invalid JSON: {$path}");

        return $decoded;
    }
}
