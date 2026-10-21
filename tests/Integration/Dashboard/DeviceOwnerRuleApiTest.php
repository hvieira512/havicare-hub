<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Http\ApiError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

/** Um dispositivo tem empresa e licença, ou nenhuma das duas. */
final class DeviceOwnerRuleApiTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function halfOwners(): array
    {
        return [
            'company without licence' => [['company' => 'hitcare'], 'licenseId'],
            'licence without company' => [['licenseId' => 1001], 'company'],
        ];
    }

    /** @param array<string, mixed> $owner */
    #[DataProvider('halfOwners')]
    public function testCreatingWithHalfAnOwnerIsRefused(array $owner, string $missing): void
    {
        [$api] = $this->makeApi();

        $result = $api->create(['imei' => '865028000000306', 'supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner);

        self::assertSame('incomplete_owner', $result['error']['code'] ?? null);
        self::assertArrayHasKey($missing, $result['error']['fields'] ?? []);
        self::assertSame(422, ApiError::declaredStatus('incomplete_owner'));
    }

    /** @param array<string, mixed> $owner */
    #[DataProvider('halfOwners')]
    public function testUpdatingWithHalfAnOwnerIsRefused(array $owner, string $missing): void
    {
        [$api] = $this->makeApi();

        $result = $api->update('861265061009822', ['supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner);

        self::assertSame('incomplete_owner', $result['error']['code'] ?? null);
        self::assertArrayHasKey($missing, $result['error']['fields'] ?? []);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function sentinelOwners(): array
    {
        return [
            'empty company and zero licence' => [['company' => '', 'licenseId' => 0]],
            'null text company and zero licence' => [['company' => 'null', 'licenseId' => 0]],
        ];
    }

    /**
     * Sem dono é `null`: o `""`, o `'null'` e o `0` são sentinelas de dentro do hub.
     *
     * @param array<string, mixed> $owner
     */
    #[DataProvider('sentinelOwners')]
    public function testSentinelsAreRefusedAsNoOwner(array $owner): void
    {
        [$api] = $this->makeApi();

        $created = $api->create(['imei' => '865028000000306', 'supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner);
        $updated = $api->update('861265061009822', ['supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner);

        self::assertSame(['company', 'licenseId'], array_keys($created['error']['fields'] ?? []));
        self::assertSame(['company', 'licenseId'], array_keys($updated['error']['fields'] ?? []));
    }

    public function testAWholeOwnerOrNoneIsAccepted(): void
    {
        [$api] = $this->makeApi();

        $none = $api->create([
            'imei' => '865028000000306',
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'company' => null,
            'licenseId' => null,
        ]);
        $whole = $api->create([
            'imei' => '865028000000307',
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'company' => 'hitcare',
            'licenseId' => 1001,
        ]);

        self::assertSame('ok', $none['status'] ?? null);
        self::assertSame('ok', $whole['status'] ?? null);
    }
}
