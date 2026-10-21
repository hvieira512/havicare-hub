<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

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
}
