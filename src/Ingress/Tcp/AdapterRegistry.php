<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

use Hub\Ingress\Tcp\FourPTouch\FourPTouchAdapter;
use Hub\Ingress\Tcp\Vivistar\VivistarAdapter;
use Hub\Ingress\Tcp\Wonlex\WonlexAdapter;
use Hub\Ingress\Tcp\Zayata\ZayataAdapter;

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
        $this->register(new ZayataAdapter());
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

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
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

    /**
     * O adaptador deste protocolo, ou uma avaria: um de recurso seria uma segunda instância, fora
     * do registo e sem o estado que ele partilha.
     */
    public function require(string $protocol): DeviceAdapterInterface
    {
        return $this->adapters[$protocol]
            ?? throw new \RuntimeException("Nenhum adaptador registado para o protocolo {$protocol}");
    }

    /** @return list<string> */
    public function protocols(): array
    {
        return array_keys($this->adapters);
    }
}
