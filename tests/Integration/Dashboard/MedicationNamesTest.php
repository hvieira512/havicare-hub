<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

/** O nome do medicamento de cada compartimento do M228 fica no hub: o aparelho só sabe as horas. */
final class MedicationNamesTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    private const IMEI = '869243062262262';

    public function testTheNameIsStoredAndReturnedButNeverSent(): void
    {
        $submitted = [];
        [$api] = $this->dispenserApi($submitted);

        $response = $api->updateConfigurations(self::IMEI, ['configurations' => [
            'medication_reminders' => $this->plan('Metformina', '08:00'),
        ]]);

        self::assertSame('ok', $response['status'] ?? null, json_encode($response) ?: '');
        self::assertCount(1, $submitted);
        self::assertStringNotContainsString(bin2hex('Metformina'), bin2hex($submitted[0]['bytes']));
        self::assertSame('Metformina', $response['configurations']['medication_reminders']['plans'][0]['name'] ?? null);
        self::assertSame(
            'Metformina',
            $api->show(self::IMEI, null, '')['configurations']['medication_reminders']['plans'][0]['name'] ?? null,
        );
    }

    public function testChangingOnlyTheNameIsAppliedWithoutADownlink(): void
    {
        $submitted = [];
        [$api, $db] = $this->dispenserApi($submitted);
        $first = $api->updateConfigurations(self::IMEI, ['configurations' => [
            'medication_reminders' => $this->plan('Metformina', '08:00'),
        ]]);
        $db->deviceConfigurations->updateOperation((string)$first['results'][0]['operations'][0]['lastCommandId'], 'acked');

        $response = $api->updateConfigurations(self::IMEI, ['configurations' => [
            'medication_reminders' => $this->plan('Atorvastatina', '08:00'),
        ]]);

        self::assertCount(1, $submitted, 'um plano que só muda nomes não vai ao aparelho');
        self::assertSame('confirmed', $response['configurationSync']['entries']['reminders']['medication_reminders']['status'] ?? null);
        self::assertSame('Atorvastatina', $response['configurations']['medication_reminders']['plans'][0]['name'] ?? null);
        self::assertSame('Atorvastatina', $response['effectiveConfigurations']['medication_reminders']['plans'][0]['name'] ?? null);
    }

    /** Com a hora ainda por confirmar, mudar o nome não pode dar por aplicada a hora. */
    public function testANameChangeOverAnUnconfirmedTimeStillTravels(): void
    {
        $submitted = [];
        [$api] = $this->dispenserApi($submitted);
        $api->updateConfigurations(self::IMEI, ['configurations' => [
            'medication_reminders' => $this->plan('Metformina', '08:00'),
        ]]);

        $response = $api->updateConfigurations(self::IMEI, ['configurations' => [
            'medication_reminders' => $this->plan('Atorvastatina', '08:00'),
        ]]);

        self::assertCount(2, $submitted);
        self::assertNotSame('confirmed', $response['configurationSync']['entries']['reminders']['medication_reminders']['status'] ?? null);
    }

    public function testANameThatIsNotTextIsRefused(): void
    {
        $submitted = [];
        [$api] = $this->dispenserApi($submitted);

        $response = $api->updateConfigurations(self::IMEI, ['configurations' => [
            'medication_reminders' => ['plans' => [['name' => ['x'], 'times' => [['time' => '08:00', 'slot' => 1]]]]],
        ]]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame([], $submitted);
    }

    /** @return array{plans: list<array<string, mixed>>} */
    private function plan(string $name, string $time): array
    {
        return ['plans' => [[
            'name' => $name,
            'times' => [['time' => $time, 'enabled' => true, 'slot' => 1, 'recurrence' => ['kind' => 'daily']]],
        ]]];
    }

    /**
     * @param list<array{imei: string, bytes: string}> $submitted
     * @return array{0: \Hub\Api\Services\DeviceService, 1: \Hub\Infrastructure\Persistence\Repository\ApiDataAccess}
     */
    private function dispenserApi(array &$submitted): array
    {
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $db->whitelist->register(self::IMEI, 'Zayata', 'M228', 'pill_dispenser');
        $store->runtime->registerDevice(self::IMEI, 'Zayata', 'M228', 'pill_dispenser');
        $model = $db->models->find('Zayata', 'M228');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);

        return [$api, $db];
    }
}
