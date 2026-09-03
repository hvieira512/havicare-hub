<?php

namespace Hub\Domain;

/**
 * Os sete campos que descrevem um dispositivo na whitelist.
 *
 * A mesma leitura defensiva -- `deviceType` ou `device_type`, `licenseId` ou `license_id`,
 * um `(string)` e um `??` por campo -- estava espalhada por dezenas de sítios, porque a
 * whitelist entregava um array e cada consumidor tinha de voltar a adivinhar a forma. O
 * `fromArray()` faz essa leitura uma vez, na fronteira, e a partir daí os campos são
 * propriedades com tipo: quem os lê já não tem de os normalizar outra vez.
 */
final class DeviceMetadata
{
    public function __construct(
        public readonly string $supplier,
        public readonly string $model,
        public readonly string $deviceType = 'watch',
        public readonly int $licenseId = 0,
        public readonly string $company = 'null',
        public readonly string $simNumber = '',
        public readonly string $deviceId = '',
    ) {
    }

    /**
     * @param array<string, mixed> $value uma linha da whitelist, do ficheiro JSON ou do MySQL
     */
    public static function fromArray(array $value): self
    {
        // O ficheiro usa camelCase e o MySQL snake_case, e a mesma entrada pode chegar por
        // qualquer um dos dois caminhos.
        $deviceId = trim((string)($value['deviceId'] ?? $value['device_id'] ?? ''));
        if ($deviceId === '') {
            $deviceId = trim((string)($value['sourceDeviceId'] ?? $value['source_device_id'] ?? ''));
        }

        return new self(
            trim((string)($value['supplier'] ?? '')),
            trim((string)($value['model'] ?? '')),
            self::normalizeDeviceType((string)($value['deviceType'] ?? $value['device_type'] ?? 'watch')),
            self::normalizeLicenseId((string)($value['licenseId'] ?? $value['license_id'] ?? '0')),
            self::normalizeCompany((string)($value['company'] ?? 'null')),
            trim((string)($value['simNumber'] ?? $value['sim_number'] ?? '')),
            $deviceId,
        );
    }

    /**
     * Para quem precisa mesmo de um array: as respostas JSON e o ficheiro da whitelist.
     *
     * @return array{supplier: string, model: string, deviceType: string, licenseId: int, company: string, simNumber: string, deviceId: string}
     */
    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier,
            'model' => $this->model,
            'deviceType' => $this->deviceType,
            'licenseId' => $this->licenseId,
            'company' => $this->company,
            'simNumber' => $this->simNumber,
            'deviceId' => $this->deviceId,
        ];
    }

    public static function normalizeDeviceType(string $deviceType): string
    {
        $normalized = strtolower(trim($deviceType));

        return in_array($normalized, DeviceTypeCatalog::keys(), true) ? $normalized : 'watch';
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
