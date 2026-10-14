<?php

declare(strict_types=1);

namespace Tests\Integration\Runtime;

use Hub\Api\Services\DeviceService;
use Hub\Registry\Whitelist;
use Hub\Runtime\NamedTimeZoneFollower;
use React\Http\Message\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/** Um dispensador com fuso de Lisboa recebe o desvio novo e a hora certa quando a hora muda. */
final class NamedTimeZoneFollowerTest extends DashboardHttpTestCase
{
    private const IMEI = '869243062262262';

    public function testTheZoneIsRewrittenAndTheClockSetOnlyWhenTheOffsetChanged(): void
    {
        $sent = [];
        $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $_imei, string $_bytes, ?array $context = null) use (&$sent): string {
            $sent[] = (string)($context['command'] ?? '');
            return 'sent';
        });
        [$server, $db, $store] = $this->makeServerWithDatabase($hub);
        $db->whitelist->register(self::IMEI, 'Zayata', 'M228', 'pill_dispenser', 1001, '', '', 'hitcare');
        $store->registerDevice(self::IMEI, 'Zayata', 'M228', 'pill_dispenser', 1001, '', '', 'hitcare');
        $model = $db->models->find('Zayata', 'M228');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['time_zone', 'calibrate_clock']);

        $token = $this->loginToken($server, 'admin', 'secret');
        // O desvio de agora já lá está: escolher a zona tem de ficar guardado na mesma.
        $now = \Hub\Domain\Capability\TimeZoneOffset::hhmm('Europe/Lisbon', new \DateTimeImmutable());
        $server(new ServerRequest(
            'PATCH',
            '/api/devices/' . self::IMEI . '/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['configurations' => ['time_zone' => ['timeZone' => $now]]], JSON_THROW_ON_ERROR)
        ));
        $sent = [];
        $patch = $server(new ServerRequest(
            'PATCH',
            '/api/devices/' . self::IMEI . '/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['configurations' => ['time_zone' => ['timeZone' => 'Europe/Lisbon']]], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(200, $patch->getStatusCode(), (string)$patch->getBody());
        self::assertSame(['timeZone'], $sent);

        $follower = new NamedTimeZoneFollower(
            new DeviceService($store, new Whitelist($this->whitelistPath, $db->whitelist), $hub, $db),
            $db->configurationLifecycle,
            static fn (): array => [self::IMEI],
        );

        self::assertCount(0, $follower->tick(), 'com o desvio igual não sai nada');

        // O desvio que ficou guardado é o da outra estação do ano.
        $db->deviceConfigurations->saveDesired(self::IMEI, 'time_zone', 'zayata-m228', 'time_zone', ['timeZone' => 999]);

        self::assertSame([self::IMEI], $follower->tick());
        self::assertSame(['timeZone', 'timeZone', 'calibrateClock'], $sent);
        self::assertCount(0, $follower->tick());
    }
}
