<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Http\ApiError;
use Hub\Api\Request\DeviceWriteRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\DevicesApiFixture;

/** Um dispositivo tem empresa e licença, ou nenhuma das duas. */
final class DeviceOwnerRuleApiTest extends DashboardHttpTestCase
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

        $result = $api->create(new DeviceWriteRequest(
            ...['imei' => '865028000000306', 'supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner,
        ));

        self::assertSame('incomplete_owner', $result['error']['code'] ?? null);
        self::assertArrayHasKey($missing, $result['error']['fields'] ?? []);
        self::assertSame(422, ApiError::declaredStatus('incomplete_owner'));
    }

    /** @param array<string, mixed> $owner */
    #[DataProvider('halfOwners')]
    public function testUpdatingWithHalfAnOwnerIsRefused(array $owner, string $missing): void
    {
        [$api] = $this->makeApi();

        $result = $api->update('861265061009822', new DeviceWriteRequest(
            ...['supplier' => 'Vivistar', 'model' => 'L08 Pro'] + $owner,
        ));

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
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');
        $model = ['supplier' => 'Vivistar', 'model' => 'L08 Pro'];

        $created = $this->write($server, 'POST', '/api/devices', $token, ['imei' => '865028000000306'] + $model + $owner);
        $updated = $this->write($server, 'PUT', '/api/devices/861265061009822', $token, $model + $owner);

        self::assertSame(['company', 'licenseId'], array_keys($created['error']['fields'] ?? []));
        self::assertSame(['company', 'licenseId'], array_keys($updated['error']['fields'] ?? []));
    }

    public function testAWholeOwnerOrNoneIsAccepted(): void
    {
        [$api] = $this->makeApi();

        $none = $api->create(new DeviceWriteRequest(imei: '865028000000306', supplier: 'Vivistar', model: 'L08 Pro'));
        $whole = $api->create(new DeviceWriteRequest(
            imei: '865028000000307',
            supplier: 'Vivistar',
            model: 'L08 Pro',
            licenseId: 1001,
            company: 'hitcare',
        ));

        self::assertSame('ok', $none['status'] ?? null);
        self::assertSame('ok', $whole['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function write(callable $server, string $method, string $path, string $token, array $body): array
    {
        $response = $server(new ServerRequest(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        ));

        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
