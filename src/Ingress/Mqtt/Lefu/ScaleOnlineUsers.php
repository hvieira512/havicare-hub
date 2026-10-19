<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

use Predis\ClientInterface;

/** Quem cada balança aceitou em linha por último; ela lembra-se dele entre ligações, e o hub também. */
final class ScaleOnlineUsers
{
    /** @var array<string, string> sem Redis, só enquanto o processo vive */
    private array $local = [];

    public function __construct(
        private readonly ?ClientInterface $redis = null,
        private readonly string $key = 'hub:lefu:online-user',
    ) {
    }

    public function get(string $imei): string
    {
        if ($this->redis === null) {
            return $this->local[$imei] ?? '';
        }

        return (string)$this->redis->hget($this->key, $imei);
    }

    public function set(string $imei, string $userId): void
    {
        if ($this->redis === null) {
            $this->local[$imei] = $userId;
            return;
        }

        $this->redis->hset($this->key, $imei, $userId);
    }
}
