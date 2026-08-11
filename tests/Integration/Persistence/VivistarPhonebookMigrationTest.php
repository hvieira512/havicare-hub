<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Hub\Infrastructure\Persistence\Migration\Version2026081101MigrateVivistarPhonebookRows;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Vivistar's BP14 contact list was renamed from "phonebook" to
 * "call_whitelist" without migrating the rows already written, leaving devices
 * with a configuration their model does not support and the API refuses to
 * edit. These cases mirror what production actually held.
 */
final class VivistarPhonebookMigrationTest extends MysqlDashboardTestCase
{
    private function insertConfig(PDO $pdo, string $imei, string $key, string $protocol, string $payload): void
    {
        $pdo->prepare('
            INSERT INTO device_configurations
                (imei, config_key, native_key, protocol, supplier, model, command, desired_payload, reported_payload, desired_revision, last_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ')->execute([$imei, $key, $key, $protocol, 'Vivistar', 'VL17', 'BP14', $payload, '{}', 'acked']);
    }

    /** @return list<array<string, mixed>> */
    private function configs(PDO $pdo, string $imei): array
    {
        $stmt = $pdo->prepare('SELECT config_key, native_key, command, desired_payload FROM device_configurations WHERE imei = ? ORDER BY config_key');
        $stmt->execute([$imei]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testAPhonebookWithNoWhitelistIsCarriedOverWithItsContacts(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $contacts = '{"contacts":[{"name":"lar s jose","phone":"+351258777510"}]}';
        $this->insertConfig($pdo, '861265061274392', 'phonebook', 'vivistar-iw', $contacts);

        (new Version2026081101MigrateVivistarPhonebookRows())->up($pdo);

        $rows = $this->configs($pdo, '861265061274392');
        self::assertCount(1, $rows);
        self::assertSame('call_whitelist', $rows[0]['config_key']);
        self::assertSame('call_whitelist', $rows[0]['native_key']);
        self::assertSame('BP14', $rows[0]['command']);
        // The operator entered these; a rename must not drop them.
        self::assertSame($contacts, $rows[0]['desired_payload']);
    }

    public function testAPhonebookSupersededByAWhitelistIsRemoved(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->insertConfig($pdo, '861265062542599', 'phonebook', 'vivistar-iw', '{"contacts":[{"name":"OLD","phone":"+351258248177"}]}');
        $this->insertConfig($pdo, '861265062542599', 'call_whitelist', 'vivistar-iw', '{"contacts":[]}');

        (new Version2026081101MigrateVivistarPhonebookRows())->up($pdo);

        $rows = $this->configs($pdo, '861265062542599');
        self::assertCount(1, $rows);
        self::assertSame('call_whitelist', $rows[0]['config_key']);
        // The newer row is the current intent and must survive untouched.
        self::assertSame('{"contacts":[]}', $rows[0]['desired_payload']);
    }

    public function testPhonebooksOnProtocolsThatHaveOneAreLeftAlone(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $payload = '{"contacts":[{"name":"Ana","phone":"+351912345678"}]}';
        $this->insertConfig($pdo, '868705080300697', 'phonebook', 'wonlex-json', $payload);
        $this->insertConfig($pdo, '351266770073676', 'phonebook', 'four-p-touch', $payload);

        (new Version2026081101MigrateVivistarPhonebookRows())->up($pdo);

        foreach (['868705080300697', '351266770073676'] as $imei) {
            $rows = $this->configs($pdo, $imei);
            self::assertSame('phonebook', $rows[0]['config_key'], "{$imei} genuinely has a phone book");
            self::assertSame($payload, $rows[0]['desired_payload']);
        }
    }

    public function testRunningItTwiceChangesNothingFurther(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->insertConfig($pdo, '861265061274392', 'phonebook', 'vivistar-iw', '{"contacts":[]}');

        $migration = new Version2026081101MigrateVivistarPhonebookRows();
        $migration->up($pdo);
        $first = $this->configs($pdo, '861265061274392');
        $migration->up($pdo);

        self::assertSame($first, $this->configs($pdo, '861265061274392'));
    }
}
