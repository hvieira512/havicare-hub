<?php

namespace Hub\Protocol;

use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;

class AdapterRegistry
{
    /** @var array<string, DeviceAdapterInterface> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [];
        $this->register(new WonlexAdapter());
        $this->register(new VivistarAdapter());
        $this->register(new FourPTouchAdapter());
    }

    public function register(DeviceAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->protocol()] = $adapter;
    }

    public function detectFromMessage(string $raw): ?DeviceAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->canDecode($raw)) {
                return $adapter;
            }
        }

        return null;
    }

    public function decodeAny(string $raw, array $context = []): ?array
    {
        $adapter = $this->detectFromMessage($raw);
        if ($adapter === null) {
            return null;
        }

        $payload = $adapter->decodeIncoming($raw, $context);
        if ($payload === null) {
            return null;
        }

        $payload['_protocol'] = $adapter->protocol();
        return $payload;
    }

    public function get(string $protocol): ?DeviceAdapterInterface
    {
        return $this->adapters[$protocol] ?? null;
    }

    public function protocols(): array
    {
        return array_keys($this->adapters);
    }

}
