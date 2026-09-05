<?php

namespace Hub\Domain\Capability;

use Hub\Domain\DiaperSensitivity;

/**
 * A sensibilidade dos alertas de um medidor de fraldas. O valor é
 * `{pollutionRange, pollutionValue}` nos dois sentidos, e o `_meta` leva os presets, as gamas
 * e a graduação.
 *
 * `HubAppliedCapability` porque não há downlink: o que estes valores mudam é a regra com que
 * o `Moko\Bridge` interpreta a leitura. Os limiares e a validação estão no
 * `DiaperSensitivity`.
 */
final class DiaperSensitivityCapability implements
    CapabilityContract,
    CapabilityInputSanitizer,
    HubAppliedCapability
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'diaper_sensitivity';
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return false;
    }

    public function supportedProtocols(): array
    {
        return ['monit-mecs-pro-ble'];
    }

    /**
     * Nunca chamado: uma capacidade aplicada no hub não passa pela conversão para nativo.
     * Fica explícito em vez de devolver vazio, para que um caminho novo que a chame por
     * engano falhe em vez de gravar nada em silêncio.
     */
    public function toNative(string $protocol, mixed $value): array
    {
        throw new \InvalidArgumentException(
            'diaper_sensitivity is applied by the hub and has no native command'
        );
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return [
            'pollutionRange' => (int)($desired['pollutionRange'] ?? DiaperSensitivity::normal()['pollutionRange']),
            'pollutionValue' => (int)($desired['pollutionValue'] ?? DiaperSensitivity::normal()['pollutionValue']),
        ];
    }

    public function defaultValue(string $protocol): mixed
    {
        // A ausência de linha é o preset normal e não um erro, e é por isso que não há
        // backfill: um sensor que ninguém configurou usa o preset.
        return DiaperSensitivity::normal();
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return array_replace_recursive([
            'presets' => DiaperSensitivity::PRESETS,
            'bounds' => [
                'pollutionRange' => DiaperSensitivity::RANGE_BOUNDS,
                'pollutionValue' => DiaperSensitivity::VALUE_BOUNDS,
            ],
            'grades' => [
                'pollutionRange' => DiaperSensitivity::RANGE_GRADES,
                'pollutionValue' => DiaperSensitivity::VALUE_GRADES,
            ],
        ], $accumulatedMeta);
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        $pair = is_array($value) ? $value : DiaperSensitivity::normal();
        $range = (int)($pair['pollutionRange'] ?? 0);
        $pollution = (int)($pair['pollutionValue'] ?? 0);

        return [
            // O perfil é derivado dos valores e nunca guardado: guardar os dois deixava-os
            // discordar, e o "personalizado" sai de graça.
            'value' => $pair + ['profile' => DiaperSensitivity::profile($range, $pollution)],
            '_meta' => $this->meta($protocol, $meta),
        ];
    }

    /** Rejeita um par fora das gamas que a app da MONIT aceita. */
    public function sanitizeInput(string $protocol, mixed $value): mixed
    {
        $pair = is_array($value) ? $value : [];
        $range = (int)($pair['pollutionRange'] ?? DiaperSensitivity::normal()['pollutionRange']);
        $pollution = (int)($pair['pollutionValue'] ?? DiaperSensitivity::normal()['pollutionValue']);

        $error = DiaperSensitivity::validate($range, $pollution);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        return ['pollutionRange' => $range, 'pollutionValue' => $pollution];
    }
}
