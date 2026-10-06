<?php

declare(strict_types=1);

namespace Hub\Protocol;

use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Protocol\Adapter\VeepooAdapter;
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
        $this->register(new VeepooAdapter());
        $this->register(new PillDispenserAdapter());
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

    /**
     * O adaptador deste protocolo, ou uma avaria.
     *
     * Quem precisa dele não tem caminho alternativo: construir um de recurso dava uma segunda
     * instância, fora do registo e sem o estado que o registo partilha.
     */
    public function require(string $protocol): DeviceAdapterInterface
    {
        return $this->adapters[$protocol]
            ?? throw new \RuntimeException("Nenhum adaptador registado para o protocolo {$protocol}");
    }

    public function protocols(): array
    {
        return array_keys($this->adapters);
    }
}
