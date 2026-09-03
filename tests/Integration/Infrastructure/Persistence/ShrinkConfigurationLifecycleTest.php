<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\ShrinkConfigurationLifecycle;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A conversão que esta migração faz às linhas que já existem.
 *
 * O `MigrationTest` não testa o que cada migração faz, e com razão: o destino da estrutura
 * afirma-se no `SchemaCompletenessTest`. Esta é a excepção porque converte **dados** -- reescreve
 * 189 payloads de produção, alguns com 978 KB -- e uma base nova não tem linhas onde o destino
 * se possa observar. Corre uma vez e não tem volta.
 *
 * Retira-se com a migração, quando ela sair do plano.
 */
final class ShrinkConfigurationLifecycleTest extends MysqlDashboardTestCase
{
    /** Devolve a base à forma anterior, que é onde a migração tem de saber entrar. */
    private function databaseInTheOldShape(): PDO
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("ALTER TABLE device_configuration_operations
            ADD COLUMN command_bytes LONGTEXT NOT NULL,
            ADD COLUMN expected_reply_types LONGTEXT NOT NULL,
            ADD COLUMN retry_delay_seconds INT UNSIGNED NOT NULL DEFAULT 60,
            ADD INDEX idx_configuration_operation_dispatch (delivery_status, updated_at)");
        $pdo->exec('DROP INDEX idx_whitelist_license_device_type ON whitelist');
        $pdo->exec('CREATE INDEX idx_whitelist_device_type_license ON whitelist (device_type, license_id)');
        $pdo->exec('DROP INDEX idx_device_config_change ON device_configurations');
        $pdo->exec('CREATE INDEX idx_device_config_current_change ON device_configurations (imei, current_change_id)');
        $pdo->exec('CREATE INDEX idx_model_capabilities_model ON model_capabilities (model_id)');
        $pdo->exec('CREATE INDEX idx_licenses_company_id ON licenses (company_id)');
        $pdo->exec('CREATE INDEX idx_private_radio_map_usable ON private_radio_map_access_points (conflicted, last_seen_at)');

        return $pdo;
    }

    private function insertChange(PDO $pdo, string $changeId, string $desired, ?string $effective): void
    {
        $pdo->prepare('
            INSERT INTO device_configuration_changes (
                change_id, imei, config_key, desired_revision, desired_payload,
                effective_payload, sync_status, created_at, updated_at
            ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)
        ')->execute([
            $changeId, '861728087060467', 'medication_reminders', $desired, $effective,
            'confirmed', '2026-08-04T07:58:44Z', '2026-08-04T07:58:44Z',
        ]);
    }

    /** @return array<string, mixed> */
    private function payloadOf(PDO $pdo, string $changeId, string $column): array
    {
        $stmt = $pdo->prepare("SELECT {$column} FROM device_configuration_changes WHERE change_id = ?");
        $stmt->execute([$changeId]);
        $decoded = json_decode((string)$stmt->fetchColumn(), true);
        self::assertIsArray($decoded, "o {$column} tem de continuar a ser JSON de objecto");

        return $decoded;
    }

    public function testTheStoredAudioBecomesAMarkerAndTheRestOfThePayloadSurvives(): void
    {
        $pdo = $this->databaseInTheOldShape();
        $audio = "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\x00", 5000);
        $desired = json_encode([
            'reminderSettings' => [['time' => '15:26', 'enabled' => true]],
            'reminderText' => 'Teste',
            'voiceData' => base64_encode($audio),
            'voiceMimeType' => 'audio/mpeg',
        ]);
        self::assertIsString($desired);
        $this->insertChange($pdo, 'change-audio', $desired, $desired);

        (new ShrinkConfigurationLifecycle())->up($pdo);

        foreach (['desired_payload', 'effective_payload'] as $column) {
            $payload = $this->payloadOf($pdo, 'change-audio', $column);
            self::assertArrayNotHasKey('voiceData', $payload, "o áudio devia sair do {$column}");
            self::assertTrue($payload['voiceDataAvailable']);
            self::assertSame(strlen($audio), $payload['voiceDataBytes']);
            self::assertSame('Teste', $payload['reminderText']);
            self::assertSame([['time' => '15:26', 'enabled' => true]], $payload['reminderSettings']);
        }
    }

    public function testADataUriIsCountedByItsDecodedSizeAndNotByItsPrefix(): void
    {
        // Duas linhas de produção guardaram o data URI inteiro em vez do base64 puro.
        $pdo = $this->databaseInTheOldShape();
        $audio = str_repeat("\x01", 3000);
        $desired = json_encode(['voiceData' => 'data:audio/mpeg;base64,' . base64_encode($audio)]);
        self::assertIsString($desired);
        $this->insertChange($pdo, 'change-datauri', $desired, null);

        (new ShrinkConfigurationLifecycle())->up($pdo);

        $payload = $this->payloadOf($pdo, 'change-datauri', 'desired_payload');
        self::assertArrayNotHasKey('voiceData', $payload);
        self::assertSame(strlen($audio), $payload['voiceDataBytes']);
    }

    public function testAChangeWithoutAudioAndANullEffectivePayloadAreLeftAlone(): void
    {
        $pdo = $this->databaseInTheOldShape();
        $desired = json_encode(['enabled' => true, 'intervalMinutes' => 15]);
        self::assertIsString($desired);
        $this->insertChange($pdo, 'change-plain', $desired, null);

        (new ShrinkConfigurationLifecycle())->up($pdo);

        self::assertSame(['enabled' => true, 'intervalMinutes' => 15], $this->payloadOf($pdo, 'change-plain', 'desired_payload'));
        $stmt = $pdo->prepare('SELECT effective_payload FROM device_configuration_changes WHERE change_id = ?');
        $stmt->execute(['change-plain']);
        self::assertNull($stmt->fetchColumn(), 'um efectivo nulo continua nulo, e não vira cadeia vazia');
    }

    public function testTheStructureEndsWhereTheBaselineIsAndRunningItTwiceIsSafe(): void
    {
        $pdo = $this->databaseInTheOldShape();

        (new ShrinkConfigurationLifecycle())->up($pdo);
        (new ShrinkConfigurationLifecycle())->up($pdo);

        foreach (['command_bytes', 'expected_reply_types', 'retry_delay_seconds'] as $column) {
            self::assertNotContains($column, $this->columnsOf($pdo, 'device_configuration_operations'));
        }
        self::assertNotContains('idx_configuration_operation_dispatch', $this->indexesOf($pdo, 'device_configuration_operations'));
        self::assertNotContains('idx_private_radio_map_usable', $this->indexesOf($pdo, 'private_radio_map_access_points'));
        self::assertNotContains('idx_model_capabilities_model', $this->indexesOf($pdo, 'model_capabilities'));
        self::assertNotContains('idx_licenses_company_id', $this->indexesOf($pdo, 'licenses'));
        self::assertNotContains('idx_whitelist_device_type_license', $this->indexesOf($pdo, 'whitelist'));
        self::assertContains('idx_whitelist_license_device_type', $this->indexesOf($pdo, 'whitelist'));
        self::assertNotContains('idx_device_config_current_change', $this->indexesOf($pdo, 'device_configurations'));
        self::assertContains('idx_device_config_change', $this->indexesOf($pdo, 'device_configurations'));
    }

    /**
     * As chaves estrangeiras continuam a ter índice depois de se largar o que era prefixo:
     * é o que permite ao MariaDB aceitar o `DROP INDEX`, e o que garante que continua a
     * aceitá-lo na próxima versão.
     */
    public function testTheForeignKeysStillHaveAnIndexToStandOn(): void
    {
        $pdo = $this->databaseInTheOldShape();

        (new ShrinkConfigurationLifecycle())->up($pdo);

        $pdo->prepare('INSERT INTO companies (id, name) VALUES (?, ?)')->execute([900, 'empresa-teste']);
        $pdo->prepare('INSERT INTO licenses (company_id, license_id, name) VALUES (?, ?, ?)')
            ->execute([900, 9001, 'licença-teste']);
        self::assertSame(
            1,
            (int)$pdo->query('SELECT COUNT(*) FROM licenses WHERE license_id = 9001')->fetchColumn(),
        );
    }

    /** @return list<string> */
    private function columnsOf(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT column_name FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ?
        ');
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function indexesOf(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT DISTINCT index_name FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ?
        ');
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
