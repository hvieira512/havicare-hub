<?php

declare(strict_types=1);

namespace Hub\Command\Downlink;

use Hub\Protocol\Adapter\VivistarAdapter;

/**
 * A descida dos relógios Vivistar.
 */
final class VivistarDownlink
{
    public static function build(string $imei, string $command, array $entry, array $payload = []): string
    {
        return (new VivistarAdapter())->encodeOutgoing([
            'type' => $command,
            'imei' => $imei,
            'ident' => (string)random_int(100000, 999999),
            'data' => ['fields' => $payload['fields'] ?? ($entry['data'] ?? [])],
        ]);
    }
}
