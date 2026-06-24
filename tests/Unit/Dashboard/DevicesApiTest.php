<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Routes\Devices;
use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\DashboardDatabase;
use Hub\Dashboard\DashboardStore;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use React\Http\Message\Response;

final class DevicesApiTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-devices-api-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testShowReturnsOnlyEnabledModelRequests(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $db->modelRequestCapabilities->replaceForModelId((int)$model['id'], ['BPXL', 'BP16']);

        $response = $api->show('861265061009822');

        self::assertSame(['BPXL', 'BP16'], array_map(
            static fn(array $entry): string => (string)($entry['command'] ?? ''),
            $response['commands']
        ));
    }

    public function testShowDoesNotExposeRecentHistoryWindow(): void
    {
        [$api] = $this->makeApi();

        $response = $api->show('861265061009822');

        self::assertArrayNotHasKey('recent', $response);
        self::assertArrayHasKey('configuration', $response);
        self::assertArrayHasKey('pending', $response);
    }

    public function testLiveReturnsStreamingResponse(): void
    {
        [$api] = $this->makeApi();

        $response = $api->live('861265061009822');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('application/x-ndjson; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testCommandRejectsDisabledModelRequest(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $db->modelRequestCapabilities->replaceForModelId((int)$model['id'], ['BP16']);

        $response = $api->command('861265061009822', json_encode(['command' => 'BPXL'], JSON_THROW_ON_ERROR));

        self::assertSame('unsupported_for_model', $response['error']['code'] ?? null);
    }

    public function testCreateDerivesFourPTouchDeviceIdFromImei(): void
    {
        [$api, $db] = $this->makeApi();

        $response = $api->create(json_encode([
            'imei' => '868017032159118',
            'supplier' => '4P Touch',
            'model' => '4P-TOUCH',
            'deviceType' => 'watch',
            'licenseId' => '0',
            'deviceId' => '',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('1703215911', $api->show('868017032159118')['device']['deviceId'] ?? null);
    }

    public function testFourPTouchShowReturnsSplitHealthRequestIds(): void
    {
        [$api] = $this->makeApi();
        $api->create(json_encode([
            'imei' => '868017032159118',
            'supplier' => '4P Touch',
            'model' => '4P-TOUCH',
            'deviceType' => 'watch',
            'licenseId' => '0',
            'deviceId' => '',
        ], JSON_THROW_ON_ERROR));

        $response = $api->show('868017032159118');

        self::assertContains('fourPHeartRate', array_column($response['commands'], 'id'));
        self::assertContains('fourPSystolicPressure', array_column($response['commands'], 'id'));
        self::assertContains('fourPDiastolicPressure', array_column($response['commands'], 'id'));
        self::assertContains('fourPBodyTemperature', array_column($response['commands'], 'id'));
    }

    public function testWonlexShowKeepsPpgAndRrAsRequestCards(): void
    {
        [$api] = $this->makeApi();
        $api->create(json_encode([
            'imei' => '865028000000308',
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ], JSON_THROW_ON_ERROR));

        $response = $api->show('865028000000308');

        self::assertContains('dnPPG', array_column($response['commands'], 'id'));
        self::assertContains('dnRR', array_column($response['commands'], 'id'));
    }

    public function testCommandStatusReturnsStoredCommandById(): void
    {
        [$api] = $this->makeApi();

        $created = $api->command('861265061009822', json_encode(['command' => 'BPXL'], JSON_THROW_ON_ERROR));
        $id = (string)($created['command']['id'] ?? '');

        $response = $api->commandStatus($id);

        self::assertSame('861265061009822', $response['device']['imei'] ?? null);
        self::assertSame($id, $response['command']['id'] ?? null);
        self::assertSame('BPXL', $response['command']['requestId'] ?? null);
    }

    /**
     * @return array{0: Devices, 1: DashboardDataAccess}
     */
    private function makeApi(): array
    {
        $db = DashboardDataAccess::fromDatabase(new DashboardDatabase('file::memory:?cache=shared'));
        $store = new DashboardStore(new InMemoryRedisClientForDevicesApi(), prefix: 'test:dashboard:devices-api');
        $store->setDataAccess($db);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro');
        $whitelist = new Whitelist($this->whitelistPath);

        $api = new Devices(
            $store,
            $whitelist,
            $this->makeHubServerMock(),
            null,
            $db
        );

        return [$api, $db];
    }

    private function makeHubServerMock(): \Hub\DeviceHubServer
    {
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('sent');

        return $hub;
    }
}

final class InMemoryRedisClientForDevicesApi implements ClientInterface
{
    /** @var array<string, array<string, bool>> */
    private array $sets = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $lists = [];

    /** @var array<string, string> */
    private array $strings = [];

    public function getCommandFactory()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getOptions()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function __call($method, $arguments)
    {
        return match (strtolower((string)$method)) {
            'sadd' => $this->sadd((string)$arguments[0], (string)$arguments[1]),
            'srem' => $this->srem((string)$arguments[0], (string)$arguments[1]),
            'smembers' => $this->smembers((string)$arguments[0]),
            'hmset' => $this->hmset((string)$arguments[0], $arguments[1]),
            'hgetall' => $this->hgetall((string)$arguments[0]),
            'hset' => $this->hset((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'hget' => $this->hget((string)$arguments[0], (string)$arguments[1]),
            'lpush' => $this->lpush((string)$arguments[0], $arguments[1]),
            'ltrim' => $this->ltrim((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrange' => $this->lrange((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrem' => $this->lrem((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'setex' => $this->setex((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'get' => $this->get((string)$arguments[0]),
            'del' => $this->del($arguments[0]),
            default => throw new \BadMethodCallException("Redis method {$method} is not implemented"),
        };
    }

    private function sadd(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        $this->sets[$key][$member] = true;

        return $exists ? 0 : 1;
    }

    private function srem(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);

        return $exists ? 1 : 0;
    }

    private function smembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    private function hmset(string $key, array $dictionary): string
    {
        $this->hashes[$key] = array_merge($this->hashes[$key] ?? [], array_map('strval', $dictionary));

        return 'OK';
    }

    private function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    private function hset(string $key, string $field, string $value): int
    {
        $exists = isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $exists ? 0 : 1;
    }

    private function hget(string $key, string $field): ?string
    {
        return $this->hashes[$key][$field] ?? null;
    }

    private function lpush(string $key, array $values): int
    {
        $this->lists[$key] ??= [];
        foreach ($values as $value) {
            array_unshift($this->lists[$key], (string)$value);
        }

        return count($this->lists[$key]);
    }

    private function ltrim(string $key, int $start, int $stop): string
    {
        $this->lists[$key] = array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);

        return 'OK';
    }

    private function lrange(string $key, int $start, int $stop): array
    {
        return array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);
    }

    private function lrem(string $key, int $count, string $value): int
    {
        $removed = 0;
        $this->lists[$key] = array_values(array_filter(
            $this->lists[$key] ?? [],
            static function (string $item) use ($value, $count, &$removed): bool {
                if ($item !== $value || ($count > 0 && $removed >= $count)) {
                    return true;
                }
                $removed++;
                return false;
            }
        ));

        return $removed;
    }

    private function setex(string $key, int $ttlSeconds, string $value): string
    {
        $this->strings[$key] = $value;

        return 'OK';
    }

    private function get(string $key): ?string
    {
        return $this->strings[$key] ?? null;
    }

    private function del(array|string $keys): int
    {
        $removed = 0;
        foreach ((array)$keys as $key) {
            $removed += isset($this->hashes[$key]) || isset($this->lists[$key]) || isset($this->sets[$key]) || isset($this->strings[$key]) ? 1 : 0;
            unset($this->hashes[$key], $this->lists[$key], $this->sets[$key], $this->strings[$key]);
        }

        return $removed;
    }
}
