<?php

declare(strict_types=1);

namespace Hub\Command\Downlink;

use Hub\Protocol\Adapter\WonlexAdapter;

/**
 * A descida dos relógios Wonlex, em JSON.
 */
final class WonlexDownlink
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public static function build(string $imei, string $command, array $payload = [], array $context = []): string
    {
        $timestamp = (int)round(microtime(true) * 1000);
        $data = array_replace([
            'type' => $command,
            'imei' => $imei,
            'timestamp' => $timestamp,
        ], $payload);
        if (self::isRequestedWaveform($command)) {
            $data += self::waveformDefaults($command);
        }
        if ($command === 'dnUpSleep' && (!isset($data['upDayStr']) || !isset($data['value']))) {
            throw new \InvalidArgumentException('dnUpSleep requires upDayStr and value');
        }
        $ident = $context['ident'] ?? random_int(100000, 999999);
        if (!is_int($ident) && !(is_string($ident) && ctype_digit($ident))) {
            throw new \InvalidArgumentException('Wonlex ident must be numeric');
        }

        return (new WonlexAdapter())->encodeOutgoing([
            'type' => $command,
            'ident' => (int)$ident,
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $timestamp,
        ]);
    }

    public static function isRequestedWaveform(string $command): bool
    {
        return in_array($command, ['dnECG', 'dnHRV', 'dnPPG'], true);
    }

    /**
     * Estes valores são opcionais no contrato genérico da Wonlex, mas alguns firmwares não
     * começam a recolher a forma de onda sem eles explícitos.
     *
     * @return array{frequency:string,oneTime:int,collectionLogo:string}
     */
    public static function waveformDefaults(string $command): array
    {
        return [
            'frequency' => match ($command) {
                'dnECG' => '500',
                'dnHRV' => '100',
                default => '200',
            },
            'oneTime' => 30,
            'collectionLogo' => (string)random_int(10000000, 99999999),
        ];
    }
}
