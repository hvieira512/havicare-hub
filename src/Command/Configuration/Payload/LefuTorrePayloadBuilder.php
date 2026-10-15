<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

use Hub\Ingress\Mqtt\Lefu\TorreFrameDecoder;

/** Valida o que se configura numa balança Torre; as escritas monta-as a ponte, com o MTU da sessão. */
final class LefuTorrePayloadBuilder extends ConfigurationPayloadBuilder
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function build(string $key, array $payload): array
    {
        return match ($key) {
            'weight_unit' => ['unit' => self::unit($payload['unit'] ?? null)],
            'heart_rate_measurement', 'impedance_measurement' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled'),
            ],
            'select_scale_user', 'read_scale_history' => ['id' => self::identifier($payload['id'] ?? null, 'id')],
            'scale_users' => LefuTorreScaleUsers::withIds($payload),
            default => [],
        };
    }

    private static function unit(mixed $unit): string
    {
        if (!in_array($unit, TorreFrameDecoder::UNITS, true)) {
            throw new \InvalidArgumentException('unit must be one of ' . implode(', ', TorreFrameDecoder::UNITS));
        }

        return $unit;
    }

    /** Até 64 bytes em ASCII: é o que a balança guarda. */
    private static function identifier(mixed $value, string $field): string
    {
        $id = is_string($value) ? trim($value) : '';
        if ($id === '' || strlen($id) > 64 || !mb_check_encoding($id, 'ASCII')) {
            throw new \InvalidArgumentException("{$field} must be ASCII, 1 to 64 characters");
        }

        return $id;
    }
}
