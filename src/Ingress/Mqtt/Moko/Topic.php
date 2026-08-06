<?php

namespace Hub\Ingress\Mqtt\Moko;

final class Topic
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
