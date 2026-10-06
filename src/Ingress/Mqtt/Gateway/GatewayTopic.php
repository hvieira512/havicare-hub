<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

/**
 * O espaço de tópicos por onde um gateway publica: `{prefixo}/{empresa}/{licenca}/gw/{mac}/raw`.
 * É partilhado por todos os fornecedores; cada ingestão reclama só o que sabe ler.
 */
final class GatewayTopic
{
    public function __construct(
        public readonly string $original,
        public readonly string $gatewayMac,
    ) {
    }

    public static function parse(string $topic): ?self
    {
        $parts = explode('/', trim($topic, '/'));
        $count = count($parts);
        if ($count < 5 || $parts[$count - 3] !== 'gw' || $parts[$count - 1] !== 'raw') {
            return null;
        }

        $mac = self::normalizeMac((string)$parts[$count - 2]);
        return $mac === null ? null : new self($topic, $mac);
    }

    public static function normalizeMac(string $mac): ?string
    {
        $mac = strtolower(preg_replace('/[^0-9a-f]/i', '', trim($mac)) ?? '');
        return preg_match('/^[0-9a-f]{12}$/', $mac) === 1 ? $mac : null;
    }
}
