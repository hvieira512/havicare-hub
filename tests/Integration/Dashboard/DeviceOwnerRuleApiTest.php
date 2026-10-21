<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Http\ApiError;
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
    #[\PHPUnit\Framework\Attributes\DataProvider('halfOwners')]
    public function testCreatingWithHalfAnOwnerIsRefused(array $owner, string $missing): void
    {
        [$api] = $this->makeApi();

        $result = $api->create(['imei' => '865028000000306', 'supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner);

        self::assertSame('incomplete_owner', $result['error']['code'] ?? null);
        self::assertArrayHasKey($missing, $result['error']['fields'] ?? []);
        self::assertSame(422, ApiError::declaredStatus('incomplete_owner'));
    }

    /** @param array<string, mixed> $owner */
    #[\PHPUnit\Framework\Attributes\DataProvider('halfOwners')]
    public function testUpdatingWithHalfAnOwnerIsRefused(array $owner, string $missing): void
    {
        [$api] = $this->makeApi();

        $result = $api->update('861265061009822', ['supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner);

        self::assertSame('incomplete_owner', $result['error']['code'] ?? null);
        self::assertArrayHasKey($missing, $result['error']['fields'] ?? []);
    }

    public function testAWholeOwnerOrNoneIsAccepted(): void
    {
        [$api] = $this->makeApi();

        $none = $api->create(['imei' => '865028000000306', 'supplier' => 'Vivistar', 'model' => 'L08 Pro']);
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
