<?php

namespace Hub\Domain;

final class DeviceMetadata
{
    public static function normalizeDeviceType(string $deviceType): string
    {
        $normalized = strtolower(trim($deviceType));

        return in_array($normalized, ['watch', 'ncs', 'radar', 'gateway', 'diaper_sensor', 'bracelet'], true) ? $normalized : 'watch';
    }

    /**
     * O nome da empresa faz parte do tópico MQTT, e os tópicos distinguem maiúsculas: para
     * quem subscreve, "hitCare" e "hitcare" são dois clientes diferentes. Uma grafia só,
     * escolhida aqui, mantém os dispositivos de um cliente num sítio só.
     */
    public static function normalizeCompany(?string $company): string
    {
        $normalized = strtolower(trim((string)$company));

        return $normalized !== '' ? $normalized : 'null';
    }

    /**
     * O `licenseId` chega como inteiro pela API e como texto pelo ficheiro da whitelist e
     * pelo Redis. O inteiro é a forma canónica em memória -- é o que o controlo de acesso por
     * cliente compara --, e por isso todas as bordas convergem aqui.
     */
    public static function normalizeLicenseId(int|string $licenseId): int
    {
        $normalized = trim((string)$licenseId);

        return $normalized !== '' ? (int)$normalized : 0;
    }
}
