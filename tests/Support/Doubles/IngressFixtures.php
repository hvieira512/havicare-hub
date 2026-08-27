<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Registry\Whitelist;

/**
 * O andaime que os testes de ingress partilham.
 *
 * Todos eles precisam de uma `Whitelist`, que só sabe ler de um ficheiro, e alguns de um
 * `GatewayDeviceLinkLookup`. Montar os dois era uma dúzia de linhas repetida por ficheiro de
 * teste. Cada teste continua a declarar os seus dispositivos, que é a única parte que lhe
 * pertence.
 */
final class IngressFixtures
{
    /**
     * Um dispositivo como a whitelist o guarda. A licença e a empresa vêm juntas por omissão
     * porque uma sem a outra não é um estado válido; um dispositivo sem cliente passa as duas
     * vazias.
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
        return self::device('Qinglanst', 'RD-V1', 'radar');
    }

    /**
     * @param array<string, array<string, mixed>> $devices indexados pela chave do dispositivo
     */
    public static function whitelist(array $devices = []): Whitelist
    {
        return new Whitelist(self::whitelistPath($devices));
    }

    /**
     * O caminho, para quem precisa dele: a `Whitelist` com uma base de dados por trás leva
     * dois argumentos e é construída no próprio teste.
     *
     * O ficheiro sai do temporário do sistema no fim do processo, e não no fim do teste: um
     * teste que estoura não chega ao fim, e era assim que ficavam ficheiros para trás.
     *
     * @param array<string, array<string, mixed>> $devices indexados pela chave do dispositivo
     */
    public static function whitelistPath(array $devices = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingress-whitelist-');
        if ($path === false) {
            throw new \RuntimeException('could not create the temporary whitelist file');
        }

        file_put_contents($path, json_encode($devices, JSON_THROW_ON_ERROR));
        register_shutdown_function(static fn(): bool => @unlink($path));

        return $path;
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
