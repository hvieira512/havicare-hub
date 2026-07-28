<?php

namespace Hub\Watch;

use Hub\DeviceEventDecoder;
use Hub\Protocol\AdapterRegistry;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use Hub\Watch\Supplier\FourPTouch\FourPTouchWatchProtocol;
use Hub\Watch\Supplier\Vivistar\VivistarWatchProtocol;
use Hub\Watch\Supplier\Wonlex\WonlexWatchProtocol;

final class WatchProtocolRegistry
{
    /**
     * @var array<string, WatchProtocolInterface>
     */
    private array $protocols = [];

    public function __construct(
        ?AdapterRegistry $adapters = null,
        ?DeviceEventDecoder $eventDecoder = null,
        ?callable $wonlexStateProvider = null,
    ) {
        $adapters ??= new AdapterRegistry();
        $eventDecoder ??= new DeviceEventDecoder();

        $this->register(new WonlexWatchProtocol(
            $adapters->get('wonlex-json') ?? new WonlexAdapter(),
            $eventDecoder,
            $wonlexStateProvider
        ));
        $this->register(new VivistarWatchProtocol($adapters->get('vivistar-iw') ?? new VivistarAdapter(), $eventDecoder));
        $this->register(new FourPTouchWatchProtocol($adapters->get('four-p-touch') ?? new FourPTouchAdapter(), $eventDecoder));
    }

    public function register(WatchProtocolInterface $protocol): void
    {
        $this->protocols[$protocol->protocol()] = $protocol;
    }

    public function get(string $protocol): ?WatchProtocolInterface
    {
        return $this->protocols[$protocol] ?? null;
    }

    /**
     * @return array<string, WatchProtocolInterface>
     */
    public function all(): array
    {
        return $this->protocols;
    }

    /**
     * @return array{nativeType?: string, protocol?: string, ident?: string|int}|null
     */
    public function commandMetadata(string $bytes, ?string $protocol = null): ?array
    {
        if ($protocol !== null && $protocol !== '') {
            return $this->get($protocol)?->commandMetadata($bytes);
        }

        foreach ($this->protocols as $watchProtocol) {
            $metadata = $watchProtocol->commandMetadata($bytes);
            if ($metadata !== null) {
                return $metadata;
            }
        }

        return null;
    }
}
