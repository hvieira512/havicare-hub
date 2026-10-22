<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

use Predis\ClientInterface;

/** Quem cada balança aceitou em linha por último; ela lembra-se dele entre ligações, e o hub também. */
final class ScaleOnlineUsers
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $key = 'hub:lefu:online-user',
    ) {
    }

    public function get(string $imei): string
    {
        return (string)$this->redis->hget($this->key, $imei);
    }

    public function set(string $imei, string $userId): void
    {
        $this->redis->hset($this->key, $imei, $userId);
    }
}
