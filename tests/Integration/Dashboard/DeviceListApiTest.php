<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Request\DeviceWriteRequest;
use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceListApiTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    public function testCreateDerivesFourPTouchDeviceIdFromImei(): void
    {
        [$api, $db] = $this->makeApi();

        $response = $api->create(new DeviceWriteRequest(
            imei: '864293000000111',
            supplier: '4P Touch',
            model: 'D46',
            deviceType: 'watch',
            licenseId: null,
            deviceId: '',
        ));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('9300000011', $api->show('864293000000111', null, '')['device']['deviceId'] ?? null);
    }

    /** O filtro de modelo compara o nome inteiro: «L08» não traz o «L08 Pro». */
    public function testListModelFilterMatchesTheWholeNameAndNotAPrefix(): void
    {
        [$api] = $this->makeApi();

        self::assertSame(0, $api->list('page=1&limit=5&model=L08', null, '')['pagination']['total'] ?? null);
        self::assertSame(1, $api->list('page=1&limit=5&model=L08 Pro', null, '')['pagination']['total'] ?? null);
        self::assertSame(1, $api->list('page=1&limit=5&q=L08', null, '')['pagination']['total'] ?? null);
    }

    public function testListAcceptsSeveralValuesForTheSameFilter(): void
    {
        [$api, $db] = $this->makeApi();
        $db->whitelist->register('865028000000306', 'Wonlex', 'HW20PRO', 'radar');

        $both = $api->list('page=1&limit=10&deviceType[]=watch&deviceType[]=radar', null, '');
        $onlyRadar = $api->list('page=1&limit=10&deviceType[]=radar', null, '');

        self::assertSame(3, $both['pagination']['total'] ?? null);
        self::assertSame(1, $onlyRadar['pagination']['total'] ?? null);
    }

    /** O filtro de licença compara pares empresa e licença, e não as duas listas cruzadas. */
    public function testLicenseFilterMatchesCompanyAndLicensePairsAndNotTheirCrossProduct(): void
    {
        [$api, $db] = $this->makeApi();
        $db->whitelist->register('900000000000001', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'alfa');
        $db->whitelist->register('900000000000002', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'beta');
        $db->whitelist->register('900000000000003', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'alfa');

        $pairs = $api->list('page=1&limit=10&license[]=alfa:1001&license[]=beta:2002', null, '');
        $wholeCompany = $api->list('page=1&limit=10&license[]=alfa', null, '');

        $imeis = array_map(static fn (array $row): string => (string)$row['imei'], $pairs['data']);
        sort($imeis);

        self::assertSame(2, $pairs['pagination']['total'] ?? null);
        self::assertSame(['900000000000001', '900000000000002'], $imeis);
        // A empresa inteira é o atalho para todos os pares dela, incluindo o que ficou fora.
        self::assertSame(2, $wholeCompany['pagination']['total'] ?? null);
    }

    /** O filtro de presença entra antes da paginação, e o total sai certo. */
    public function testListFiltersByOnlineStateWithoutBreakingTheTotal(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $db->whitelist->register('900000000000005', 'Vivistar', 'L08 Pro');
        $store->deviceSeen('861265061009822', ['online' => '1']);

        $online = $api->list('page=1&limit=1&online=online', null, '');
        $offline = $api->list('page=1&limit=1&online=offline', null, '');
        $all = $api->list('page=1&limit=1', null, '');

        self::assertSame(1, $online['pagination']['total'] ?? null);
        self::assertSame('861265061009822', $online['data'][0]['imei'] ?? null);
        self::assertSame(
            (int)$all['pagination']['total'],
            (int)$online['pagination']['total'] + (int)$offline['pagination']['total']
        );
    }

    /** Os modelos agrupam-se pelo fornecedor: o mesmo nome pode existir em dois. */
    public function testListGroupsModelsUnderTheSupplierTheyBelongTo(): void
    {
        [$api, $db] = $this->makeApi();
        $db->whitelist->register('900000000000010', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'alfa');
        $db->whitelist->register('900000000000011', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'alfa');
        $db->whitelist->register('900000000000012', 'Vivistar', 'VL17', 'watch', 1001, '', '', 'alfa');
        $db->whitelist->register('900000000000013', 'MOKO', 'MKGW4', 'gateway', 1001, '', '', 'alfa');

        $tree = $api->list('page=1&limit=1', null, '')['filters']['counts']['supplierModels'] ?? [];

        $vivistar = null;
        foreach ($tree['suppliers'] ?? [] as $supplier) {
            if ($supplier['supplier'] === 'Vivistar') {
                $vivistar = $supplier;
            }
        }

        self::assertNotNull($vivistar, 'o fornecedor tem de estar na árvore');

        // Números relativos e não absolutos: o catálogo semeado já traz aparelhos, e uma
        // contagem à unidade partiria sempre que a semente mudasse.
        self::assertSame(
            array_sum(array_column($vivistar['models'], 'count')),
            $vivistar['count'],
            'a contagem do pai é a soma dos filhos'
        );

        $models = array_column($vivistar['models'], 'count', 'model');
        self::assertArrayHasKey('L08 Pro', $models);
        self::assertArrayHasKey('VL17', $models);
        self::assertGreaterThanOrEqual(2, $models['L08 Pro']);

        // Um modelo de outro fornecedor não se mistura com os deste.
        self::assertArrayNotHasKey('MKGW4', $models);

        // E a ordem é por contagem, como no resto da coluna de filtros.
        $counts = array_values($models);
        for ($i = 1; $i < count($counts); $i++) {
            self::assertGreaterThanOrEqual($counts[$i], $counts[$i - 1], 'do maior para o menor');
        }
    }

    public function testListCountsEveryOptionAndBuildsTheLicenseTree(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $db->whitelist->register('900000000000006', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'alfa');
        $db->whitelist->register('900000000000007', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'alfa');
        $store->deviceSeen('900000000000006', ['online' => '1']);

        $response = $api->list('page=1&limit=1', null, '');
        $counts = $response['filters']['counts'] ?? [];
        $tree = $counts['license'] ?? [];
        $alfa = null;
        foreach ($tree['companies'] ?? [] as $company) {
            if ($company['company'] === 'alfa') {
                $alfa = $company;
            }
        }

        self::assertNotNull($alfa);
        self::assertSame(2, $alfa['count']);
        self::assertSame([['licenseId' => 1001, 'count' => 2]], $alfa['licenses']);
        // Os que não têm empresa também não têm licença: uma não existe sem a outra.
        self::assertGreaterThan(0, (int)($tree['none'] ?? 0));

        $watch = null;
        foreach ($counts['deviceType'] ?? [] as $option) {
            if ($option['value'] === 'watch') {
                $watch = $option;
            }
        }
        self::assertNotNull($watch);
        self::assertGreaterThan(0, $watch['count']);

        // O total e quantos estão ligados não mudam com os filtros, e por isso não custam um
        // pedido por cada caixa que se marca.
        self::assertSame(4, $response['summary']['total'] ?? null);
        self::assertSame(1, $response['summary']['online'] ?? null);
    }

    public function testListReturnsAbsoluteModelImageUrl(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('Vivistar');
        self::assertIsArray($supplier);

        $db->models->add((int)$supplier['id'], 'L08 Pro', 'L08 Pro', 'watch', '/model-images/example.jpg');

        $response = $api->list('page=1&limit=5', null, 'http://localhost:8081');

        self::assertSame('http://localhost:8081/model-images/example.jpg', $response['data'][0]['image'] ?? null);
    }

    public function testDeviceGroupedRoutesCanLinkGatewayAndDiaperSensor(): void
    {
        [$api] = $this->makeApi();
        foreach (
            [
            ['imei' => 'd48c49f7909c', 'supplier' => 'MOKO', 'model' => 'MKGW3'],
            ['imei' => 'eec5000202f9', 'supplier' => 'MONIT', 'model' => 'MECS-PRO'],
            ] as $device
        ) {
            $result = $api->create(new DeviceWriteRequest(...$device, licenseId: 1001, company: 'hitcare'));
            self::assertSame('ok', $result['status'] ?? null);
        }

        self::assertSame('ok', $api->createLink('d48c49f7909c', 'eec5000202f9')['status'] ?? null);
        $links = $api->links('d48c49f7909c');
        self::assertSame('eec5000202f9', $links['data'][0]['deviceKey'] ?? null);
        self::assertSame('diaper_sensor', $links['data'][0]['deviceType'] ?? null);
        self::assertSame('eec5000202f9', $api->show('d48c49f7909c', null, '')['linkedDevices'][0]['deviceKey'] ?? null);
        $sensor = $api->show('eec5000202f9', null, '');
        self::assertArrayHasKey('diaper_moisture', $sensor['capabilities']['telemetry'] ?? []);
        self::assertArrayHasKey('diaper_condition', $sensor['capabilities']['telemetry'] ?? []);
        self::assertArrayNotHasKey('diaper_moisture', $sensor['capabilities']['health'] ?? []);
        self::assertArrayHasKey('diaper_moisture_level', $sensor['capabilities']['telemetry'] ?? []);
        self::assertFalse($sensor['capabilities']['telemetry']['diaper_moisture']['requestable'] ?? true);
        self::assertFalse($sensor['capabilities']['telemetry']['diaper_condition']['requestable'] ?? true);
        // Sem botão de pedido: o sensor é BLE passivo e não aceita pedidos, como os outros dois.
        self::assertFalse($sensor['capabilities']['telemetry']['diaper_moisture_level']['requestable'] ?? true);

        self::assertSame('ok', $api->deleteLink('d48c49f7909c', 'eec5000202f9')['status'] ?? null);
        self::assertSame([], $api->links('d48c49f7909c')['data'] ?? null);
    }

    public function testDeviceGroupedRoutesCanLinkGatewayAndBracelet(): void
    {
        [$api] = $this->makeApi();
        foreach (
            [
            ['imei' => 'c5e390f30bce', 'supplier' => 'MOKO', 'model' => 'MKGW4'],
            ['imei' => 'fbd87c59ba8b', 'supplier' => 'MOKO', 'model' => 'W6B'],
            ['imei' => '861265061009823', 'supplier' => 'Vivistar', 'model' => 'L08 Pro'],
            ] as $device
        ) {
            $result = $api->create(new DeviceWriteRequest(...$device, licenseId: 1001, company: 'hitcare'));
            self::assertSame('ok', $result['status'] ?? null);
        }

        self::assertSame('ok', $api->createLink('c5e390f30bce', 'fbd87c59ba8b')['status'] ?? null);
        self::assertSame('bracelet', $api->links('c5e390f30bce')['data'][0]['deviceType'] ?? null);

        // Um relógio não é retransmitido por BLE, por isso é recusado.
        $invalid = $api->createLink('c5e390f30bce', '861265061009823');
        self::assertSame('invalid_link', $invalid['error']['code'] ?? null);

        self::assertSame('ok', $api->deleteLink('c5e390f30bce', 'fbd87c59ba8b')['status'] ?? null);
        self::assertSame([], $api->links('c5e390f30bce')['data'] ?? null);
    }

    public function testBothSidesOfALinkSeeTheSignalThatPairWasHeardOn(): void
    {
        [$api, , $store] = $this->makeApi();
        foreach (
            [
            ['imei' => 'c5e390f30bce', 'supplier' => 'MOKO', 'model' => 'MKGW4'],
            ['imei' => 'dc1603ecf1f7', 'supplier' => 'MOKO', 'model' => 'MKGW4'],
            ['imei' => 'fbd87c59ba8b', 'supplier' => 'MOKO', 'model' => 'W6B'],
            ] as $device
        ) {
            $api->create(new DeviceWriteRequest(...$device, licenseId: 1001, company: 'hitcare'));
        }
        $api->createLink('c5e390f30bce', 'fbd87c59ba8b');
        $api->createLink('dc1603ecf1f7', 'fbd87c59ba8b');

        // Só um dos dois gateways ouve a pulseira.
        $store->recordGatewaySighting('fbd87c59ba8b', 'c5e390f30bce', -71);

        $onTheBracelet = array_column($api->show('fbd87c59ba8b', null, '')['linkedDevices'], 'rssiDbm', 'deviceKey');
        self::assertSame(-71, $onTheBracelet['c5e390f30bce'] ?? null);
        // Um gateway que nunca a ouviu tem de ficar vazio em vez de ler 0 dBm, que seria o
        // sinal mais forte que há.
        self::assertArrayNotHasKey('rssiDbm', array_column(
            $api->show('fbd87c59ba8b', null, '')['linkedDevices'],
            null,
            'deviceKey'
        )['dc1603ecf1f7']);

        // O mesmo avistamento, lido da página do gateway: fica guardado contra o dispositivo
        // retransmitido, e a ligação resolve-o dos dois lados.
        $onTheGateway = $api->show('c5e390f30bce', null, '')['linkedDevices'][0] ?? [];
        self::assertSame('fbd87c59ba8b', $onTheGateway['deviceKey'] ?? null);
        self::assertSame(-71, $onTheGateway['rssiDbm'] ?? null);
        self::assertNotSame('', $onTheGateway['signalSeenAt'] ?? '');
    }
}
