<?php

declare(strict_types=1);

namespace Tests\Support;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;

/** Uma configuração desejada na forma nativa, como o aparelho a guarda, sem passar pelo `stage()`. */
final class ConfigurationFixtures
{
    /** @param array<string, mixed> $payload */
    public static function desired(
        ApiDataAccess $db,
        string $imei,
        string $nativeKey,
        string $protocol,
        string $command,
        array $payload,
        string $status = '',
    ): void {
        // O repositório não expõe a ligação, e a escrita do desejado em bruto só existe aqui.
        $pdo = (fn(): \PDO => $this->pdo)->call($db->deviceConfigurations);
        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, command, desired_payload, reported_payload,
                last_status, desired_updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, \'{}\', ?, UTC_TIMESTAMP())
        ')->execute([
            $imei,
            CapabilityCatalog::normalizeStoredCapabilityKey($nativeKey) ?? $nativeKey,
            $nativeKey,
            $protocol,
            $command,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
        ]);
    }
}
