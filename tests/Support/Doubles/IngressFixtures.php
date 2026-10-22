<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Ingress\Mqtt\Gateway\GatewayRelay;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Registry\Whitelist;

/**
 * O andaime que os testes de ingress partilham: a `Whitelist` em memória, e o
 * `GatewayDeviceLinkLookup`. Cada teste declara os seus dispositivos.
 */
final class IngressFixtures
{
    /**
     * Um dispositivo como a whitelist o guarda: a licença e a empresa vêm juntas, e um
     * dispositivo sem cliente passa as duas vazias.
     *
     * @return array<string, string>
     */
    public static function device(
        string $supplier,
        string $model,
        string $deviceType,
        string $licenseId = '1001',
        string $company = 'hitcare',
    ): array {
        return [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
        ];
    }

    /** @return array<string, string> */
    public static function gateway(string $model = 'MKGW3'): array
    {
        return self::device('MOKO', $model, 'gateway');
    }

    /** @return array<string, string> */
    public static function bracelet(string $model = 'W6B'): array
    {
        return self::device('MOKO', $model, 'bracelet');
    }

    /** @return array<string, string> */
    public static function diaperSensor(): array
    {
        return self::device('MONIT', 'MECS-PRO', 'diaper_sensor');
    }

    /** @return array<string, string> */
    public static function radar(): array
    {
        return self::device('Qinglanst', 'HC2', 'radar');
    }

    /**
     * A chave é `array-key` e não `string`: um IMEI é só dígitos, e o PHP converte-o em
     * inteiro ao pô-lo num array.
     *
     * @param array<array-key, array<string, mixed>> $devices indexados pela chave do dispositivo
     */
    public static function whitelist(array $devices = []): Whitelist
    {
        return new Whitelist(devices: $devices);
    }


    public static function links(bool $linked = true): GatewayDeviceLinkLookup
    {
        return new class ($linked) implements GatewayDeviceLinkLookup {
            public function __construct(private bool $linked)
            {
            }

            public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
            {
                return $this->linked;
            }
        };
    }

    /** Entrega uma mensagem como o subscritor do gateway: descodificada, e só se a ponte a reclamar. */
    public static function relay(GatewayRelay $bridge, string $topic, string $payload): void
    {
        $message = json_decode($payload, true);
        if (is_array($message) && $bridge::claims($message)) {
            $bridge->handle($topic, $message);
        }
    }

    /** Constrói a mensagem de scan BLE do gateway que leva um anúncio MECS-PRO. */
    public static function monitScan(string $gateway, string $sensor, string $condition, int $battery = 80): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => $gateway],
            'data' => [[
                'adv_data' => self::monitAdvertisement($sensor, $condition, $battery),
                'rsp_data' => '0f094d4f4e4954204d4543532050524f',
                'type_code' => 10, 'type' => 'other', 'rssi' => -83,
                'connectable' => 0, 'mac' => $sensor,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Payload de 20 bytes do fabricante para uma condição: delta máximo < 4 é `clean`, quatro ou mais
     * canais com delta >= 12 é `change_required`, e o resto é `attention`.
     */
    private static function monitAdvertisement(string $sensor, string $condition, int $battery): string
    {
        $baseline = array_fill(0, 10, 1);
        $raw = match ($condition) {
            'clean' => array_fill(0, 10, 2),                        // delta 1
            'attention' => array_fill(0, 10, 7),                    // delta 6, nenhum >= 12
            'change_required' => [13, 13, 13, 13, 1, 1, 1, 1, 1, 1], // 4 canais a delta 12
            'three_wet' => [13, 13, 13, 1, 1, 1, 1, 1, 1, 1],           // 3 canais a delta 12
            default => throw new \InvalidArgumentException($condition),
        };

        $bits = str_pad(decbin(0), 3, '0', STR_PAD_LEFT)            // packetType
            . str_pad(decbin($battery), 7, '0', STR_PAD_LEFT)       // batteryPercent
            . '0' . '00' . '000';                                    // alarmType, txStrength, eventStatus
        foreach ([...$baseline, ...$raw] as $value) {
            $bits .= str_pad(decbin($value), 6, '0', STR_PAD_LEFT);
        }
        // O descodificador exige que os últimos três bytes repitam a cauda do MAC.
        foreach (str_split(substr($sensor, -6), 2) as $byte) {
            $bits .= str_pad(decbin((int)hexdec($byte)), 8, '0', STR_PAD_LEFT);
        }

        $raw20 = '';
        for ($offset = 0; $offset < 160; $offset += 8) {
            $raw20 .= sprintf('%02x', bindec(substr($bits, $offset, 8)));
        }

        $manufacturer = '59000215' . $raw20 . 'c3';
        return '020104' . sprintf('%02x', strlen($manufacturer) / 2 + 1) . 'ff' . $manufacturer;
    }
}
