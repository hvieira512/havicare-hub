<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\State\DeviceStore;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\DevicesApiFixture;

/** O hitCare está em produção e fala com a API tal como o `ajax/custom.php` e o `HubApiRepository` a chamam. */
final class HitCareContractTest extends DashboardHttpTestCase
{
    use DevicesApiFixture;

    private const FOUR_P_TOUCH = '861728087060467';
    private const VIVISTAR = '861265061009822';
    private const UNOWNED = '861265061009844';

    /** @var list<array{imei: string, bytes: string}> */
    private array $submitted = [];
    /** @var callable */
    private $server;
    private ApiDataAccess $db;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->server, $this->db, $store] = $this->makeServerWithDatabase(hub: $this->recordingHub($this->submitted));
        $this->registerFourPTouch($store);
        $this->token = $this->loginToken($this->server, 'admin', 'secret');
    }

    /** Guarda o hitCare: o `HubApiRepository` lê os quatro campos do token em snake_case, no login e na renovação. */
    public function testLoginAndRefreshGiveTheTokenFieldsHitCareReads(): void
    {
        $login = $this->call('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'secret'], null);
        $this->assertTokenFields($login);

        $refreshed = $this->call('POST', '/api/auth/login', ['refresh_token' => $login[1]['token']['refresh_token']], null);
        $this->assertTokenFields($refreshed);

        [$status, $refused] = $this->call('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'wrong'], null);
        self::assertGreaterThanOrEqual(400, $status);
        self::assertIsString($refused['error']['code'] ?? null);
    }

    /** Guarda o hitCare: o token do inquilino pede-se com o `licenseId` em número e lê-se como o do login. */
    public function testTheLicenseTokenGivesTheTokenFieldsHitCareReads(): void
    {
        $this->assertTokenFields($this->call('POST', '/api/auth/license-token', ['company' => 'hitcare', 'licenseId' => 1001]));
    }

    /** Guarda o hitCare: cada campo que o `ajax/custom.php` lê do dispositivo existe, com o tipo que ele espera. */
    public function testTheDeviceHasEveryFieldHitCareReads(): void
    {
        foreach ([self::FOUR_P_TOUCH, self::VIVISTAR] as $imei) {
            [$status, $device] = $this->call('GET', "/api/devices/{$imei}");

            self::assertSame(200, $status, json_encode($device, JSON_THROW_ON_ERROR));
            // O `getWebserviceDispositivos()` devolve o `data` em vez da resposta quando ele existe.
            self::assertArrayNotHasKey('data', $device);
            foreach (['internalModel', 'supplier', 'commercialName'] as $field) {
                self::assertIsString($device['model'][$field] ?? null, "model.{$field}");
            }
            self::assertArrayHasKey('image', $device['model']);
            // O `device.model`, o `device.supplier` e o `lastSeenAt` só se leem com valor por omissão.
            self::assertIsString($device['device']['imei'] ?? null);
            foreach (['simNumber', 'deviceId'] as $field) {
                self::assertArrayHasKey($field, $device['device'], "device.{$field}");
            }
            self::assertIsBool($device['device']['online'] ?? null);
            self::assertArrayHasKey('supported', $device['configuration']);
            self::assertArrayHasKey('stored', $device['configuration']);
            self::assertIsArray($device['configurations'] ?? null);

            $capabilities = $device['capabilities'];
            self::assertIsBool($capabilities['telemetry']['heart_rate']['supported'] ?? null);
            self::assertIsBool($capabilities['telemetry']['heart_rate']['requestable'] ?? null);
            self::assertIsArray($capabilities['health']['auto_vitals_interval'] ?? null, "{$imei} health.auto_vitals_interval");
            self::assertIsArray($capabilities['alarms']['fall_detection'] ?? null, "{$imei} alarms.fall_detection");
            self::assertIsArray($capabilities['alarms']['alarm_clock']['_meta'] ?? null, "{$imei} alarms.alarm_clock");
            self::assertIsArray($capabilities['alarms']['fall_sensitivity']['_meta']['sensitivity']['options'] ?? null);
            foreach (['sos_contacts', 'call_whitelist', 'whitelist_enabled'] as $key) {
                self::assertIsArray($capabilities['contacts'][$key] ?? null, "{$imei} contacts.{$key}");
            }
            self::assertIsInt($capabilities['contacts']['sos_contacts']['_meta']['limit'] ?? null);
        }

        $vivistar = $this->call('GET', '/api/devices/' . self::VIVISTAR)[1]['capabilities'];
        $modes = array_column($vivistar['settings_system']['working_mode']['_meta']['mode']['options'] ?? [], 'label', 'value');
        self::assertArrayHasKey(8, $modes);
        foreach (['days', 'type', 'recurrence'] as $field) {
            self::assertIsArray($vivistar['alarms']['alarm_clock']['_meta'][$field]['options'] ?? null, "alarm_clock._meta.{$field}");
        }
    }

    /**
     * Guarda o hitCare: o corpo que o `ajax/custom.php` monta para cada chave é aceite, desce ao relógio e volta a
     * ler-se com a forma que ele espera.
     */
    public function testEveryConfigurationHitCareSendsIsAcceptedSentAndReadBack(): void
    {
        $failures = [];
        foreach ([self::FOUR_P_TOUCH, self::VIVISTAR] as $imei) {
            $device = $this->call('GET', "/api/devices/{$imei}")[1];
            foreach ($this->hitCareBodies($device) as $key => $body) {
                $this->submitted = [];
                [$status, $response] = $this->call('PATCH', "/api/devices/{$imei}/configurations", ['configurations' => [$key => $body]]);
                if ($status >= 400 || isset($response['error'])) {
                    $failures[] = "{$imei} {$key}: {$status} " . json_encode($response['error'] ?? $response, JSON_THROW_ON_ERROR);
                    continue;
                }
                if ($this->downlinkCount() === 0) {
                    $failures[] = "{$imei} {$key}: no downlink";
                }
            }

            $stored = $this->call('GET', "/api/devices/{$imei}")[1]['configurations'];
            foreach ($this->readBackProblems($stored, $imei === self::VIVISTAR) as $problem) {
                $failures[] = "{$imei} read back {$problem}";
            }
        }

        self::assertSame([], $failures);
    }

    /** Guarda o hitCare: o relógio associa-se com o `licenseId` em texto, como o `ajax/custom.php` o manda, e desassocia-se. */
    public function testTheAssociationTakesTheLicenceAsTextAndIsRemoved(): void
    {
        $hitcare = $this->db->companies->findByName('hitcare');
        $this->db->licenses->create((int)$hitcare['id'], 2006, 'hitcare-2006');

        [$status, $associated] = $this->call('PATCH', '/api/devices/' . self::UNOWNED . '/association', ['company' => 'hitcare', 'licenseId' => '2006']);
        self::assertSame(200, $status, json_encode($associated, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('error', $associated);

        [$status, $removed] = $this->call('DELETE', '/api/devices/' . self::UNOWNED . '/association');
        self::assertSame(200, $status, json_encode($removed, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('error', $removed);
    }

    /** Guarda o hitCare: a lista de modelos vem no `data`, e cada um traz o `internalModel`, o `commercialName` e a `image`. */
    public function testTheModelListHasWhatHitCareReads(): void
    {
        [$status, $models] = $this->call('GET', '/api/models');

        self::assertSame(200, $status);
        self::assertIsList($models['data'] ?? null);
        self::assertNotSame([], $models['data']);
        foreach ($models['data'] as $model) {
            self::assertIsString($model['internalModel'] ?? null);
            self::assertIsString($model['commercialName'] ?? null);
            self::assertArrayHasKey('image', $model);
        }
    }

    /**
     * Os corpos tal como o `ajax/custom.php` os monta: a forma da lista de chamadas e o `type` dos alarmes saem do
     * `_meta` ou do fornecedor.
     *
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function hitCareBodies(array $device): array
    {
        $capabilities = $device['capabilities'];
        $vivistar = stripos((string)$device['model']['supplier'], 'Vivistar') !== false;
        $alarmMeta = $capabilities['alarms']['alarm_clock']['_meta'] ?? [];
        $whitelistMeta = $capabilities['contacts']['call_whitelist']['_meta'] ?? [];
        $whitelistAsContacts = isset($whitelistMeta['name']) || isset($whitelistMeta['phone'])
            || (!isset($whitelistMeta['numbers']) && $vivistar);

        $alarm = ['time' => '08:30', 'enabled' => true, 'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 5]]];
        if (isset($alarmMeta['type']) || $vivistar) {
            $alarm['type'] = (int)($alarmMeta['type']['options'][0]['value'] ?? 1);
        }

        $bodies = [
            'auto_vitals_interval' => ['enabled' => true, 'intervalMinutes' => 60],
            'fall_detection' => ['enabled' => true],
            'fall_sensitivity' => ['sensitivity' => (int)($capabilities['alarms']['fall_sensitivity']['_meta']['sensitivity']['options'][0]['value'] ?? 1)],
            'whitelist_enabled' => ['enabled' => true],
            'call_whitelist' => $whitelistAsContacts
                ? ['contacts' => [['name' => 'Filha', 'phone' => '912345678']]]
                : ['912345678'],
            'sos_contacts' => ['912345678', '913456789'],
            'alarm_clock' => ['items' => [$alarm]],
        ];
        if (isset($capabilities['contacts']['phonebook'])) {
            $bodies['phonebook'] = ['contacts' => [['name' => 'Filha', 'phone' => '912345678']]];
        }
        if ($vivistar) {
            $bodies['working_mode'] = ['mode' => 8, 'intervalSeconds' => 300, 'gpsEnabled' => true];
        }

        return $bodies;
    }

    /**
     * O que o `ajax/custom.php` espera ao ler o `configurations` de volta para desenhar o formulário.
     *
     * @param array<string, mixed> $stored
     * @return list<string>
     */
    private function readBackProblems(array $stored, bool $vivistar): array
    {
        $problems = [];
        if (($stored['sos_contacts'] ?? null) !== ['912345678', '913456789']) {
            $problems[] = 'sos_contacts ' . json_encode($stored['sos_contacts'] ?? null, JSON_THROW_ON_ERROR);
        }
        if (isset($stored['phonebook']) && ($stored['phonebook'][0]['phone'] ?? null) !== '912345678') {
            $problems[] = 'phonebook ' . json_encode($stored['phonebook'], JSON_THROW_ON_ERROR);
        }
        // O `ajax/custom.php` lê os alarmes do `items`, do `value` ou da lista em cru.
        $alarms = $stored['alarm_clock'] ?? [];
        $alarm = ($alarms['items'] ?? $alarms['value'] ?? (array_is_list($alarms) ? $alarms : []))[0] ?? null;
        if (($alarm['time'] ?? null) !== '08:30' || ($alarm['enabled'] ?? null) !== true || ($alarm['recurrence']['kind'] ?? null) !== 'custom') {
            $problems[] = 'alarm_clock ' . json_encode($stored['alarm_clock'] ?? null, JSON_THROW_ON_ERROR);
        }
        if (($stored['fall_detection']['enabled'] ?? null) !== true) {
            $problems[] = 'fall_detection ' . json_encode($stored['fall_detection'] ?? null, JSON_THROW_ON_ERROR);
        }
        if (!is_int($stored['fall_sensitivity']['sensitivity'] ?? null)) {
            $problems[] = 'fall_sensitivity ' . json_encode($stored['fall_sensitivity'] ?? null, JSON_THROW_ON_ERROR);
        }
        if (($stored['auto_vitals_interval']['enabled'] ?? null) !== true || ($stored['auto_vitals_interval']['intervalMinutes'] ?? null) !== 60) {
            $problems[] = 'auto_vitals_interval ' . json_encode($stored['auto_vitals_interval'] ?? null, JSON_THROW_ON_ERROR);
        }
        if (!str_contains(json_encode($stored['call_whitelist'] ?? null, JSON_THROW_ON_ERROR), '912345678')) {
            $problems[] = 'call_whitelist ' . json_encode($stored['call_whitelist'] ?? null, JSON_THROW_ON_ERROR);
        }
        if ($vivistar && ($stored['working_mode']['mode'] ?? null) !== 8) {
            $problems[] = 'working_mode ' . json_encode($stored['working_mode'] ?? null, JSON_THROW_ON_ERROR);
        }

        return $problems;
    }

    private function downlinkCount(): int
    {
        return count($this->submitted);
    }

    /** @param array{0: int, 1: array<string, mixed>} $response */
    private function assertTokenFields(array $response): void
    {
        [$status, $body] = $response;
        self::assertSame(200, $status, json_encode($body, JSON_THROW_ON_ERROR));
        self::assertIsString($body['token']['access_token'] ?? null);
        self::assertIsInt($body['token']['expires_in'] ?? null);
        self::assertIsString($body['token']['refresh_token'] ?? null);
        self::assertIsInt($body['token']['refresh_expires_in'] ?? null);
    }

    private function registerFourPTouch(DeviceStore $store): void
    {
        $this->db->whitelist->register(self::FOUR_P_TOUCH, '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $store->registerDevice(self::FOUR_P_TOUCH, '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null, ?string $token = 'admin'): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $response = ($this->server)(new ServerRequest($method, $path, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string)$response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }
}
