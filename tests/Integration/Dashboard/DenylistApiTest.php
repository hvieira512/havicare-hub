<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * A denylist pela API: um administrador bloqueia uma identidade (e as notificações desse
 * aparelho somem), lista, e desbloqueia. Fechada a inquilinos -- é rota de administrador.
 */
final class DenylistApiTest extends DashboardHttpTestCase
{
    public function testAdminBlocksClearsNotificationsListsAndUnblocks(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        // Um aparelho estranho já deixou uma notificação por autorizar.
        $db->dashboardNotifications->record('device_not_authorized', '357000000000123', 'four-p-touch', '4P-TOUCH', '7000000123', 'device_not_authorized');
        self::assertNotSame([], $db->dashboardNotifications->latest(100));

        // Bloquear.
        $block = $server(new ServerRequest(
            'POST',
            '/api/denylist',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token],
            json_encode(['identity' => '357000000000123', 'protocol' => 'four-p-touch', 'note' => 'vizinho'], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(200, $block->getStatusCode(), (string)$block->getBody());

        // A notificação daquele aparelho foi limpa pelo bloqueio.
        $identities = array_column($db->dashboardNotifications->latest(100), 'imei');
        self::assertNotContains('357000000000123', $identities);

        // Listar mostra a identidade bloqueada.
        $list = $server(new ServerRequest('GET', '/api/denylist', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame(200, $list->getStatusCode(), (string)$list->getBody());
        $listed = array_column(json_decode((string)$list->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [], 'identity');
        self::assertContains('357000000000123', $listed);

        // Desbloquear.
        $unblock = $server(new ServerRequest('DELETE', '/api/denylist/357000000000123', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame(200, $unblock->getStatusCode(), (string)$unblock->getBody());

        // Desbloquear de novo já não encontra nada.
        $again = $server(new ServerRequest('DELETE', '/api/denylist/357000000000123', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame(404, $again->getStatusCode(), (string)$again->getBody());
        self::assertSame(
            'denylist_not_found',
            json_decode((string)$again->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']['code'] ?? null
        );
    }

    public function testLicenseClientCannotUseTheDenylist(): void
    {
        [$server] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $blocked = $server(new ServerRequest(
            'POST',
            '/api/denylist',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token],
            json_encode(['identity' => '357000000000123'], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(403, $blocked->getStatusCode(), (string)$blocked->getBody());

        $listed = $server(new ServerRequest('GET', '/api/denylist', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame(403, $listed->getStatusCode());
    }
}
