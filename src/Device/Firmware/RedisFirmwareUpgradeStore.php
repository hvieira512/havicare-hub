<?php

declare(strict_types=1);

namespace Hub\Device\Firmware;

use Predis\ClientInterface;

final class RedisFirmwareUpgradeStore implements FirmwareUpgradeStore
{
    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:firmware-upgrade',
    ) {
        $this->prefix = trim($this->prefix, ':');
    }

    public function load(string $imei): ?array
    {
        $raw = $this->redis->get($this->key($imei));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $state = json_decode($raw, true);

        return is_array($state) ? $state : null;
    }

    public function save(string $imei, array $state): void
    {
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        // Um dia chega: uma transferência que fique a meio não pode ficar a tentar para sempre.
        $this->redis->setex($this->key($imei), 86400, $encoded);
    }

    private function key(string $imei): string
    {
        return $this->prefix . ':' . $imei;
    }
}
