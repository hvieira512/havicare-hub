<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\Firmware\FirmwareUpgradeStore;
use Hub\Protocol\AdapterRegistry;
use Hub\Ingress\Tcp\Supplier\FourPTouch\FourPTouchTcpProtocol;
use Hub\Ingress\Tcp\Supplier\Vivistar\VivistarTcpProtocol;
use Hub\Ingress\Tcp\Supplier\Wonlex\WonlexTcpProtocol;
use Hub\Ingress\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\State\ActiveAlarms;

/**
 * Os protocolos que entram pelo socket TCP do hub, indexados pelo nome do protocolo.
 */
final class TcpProtocolRegistry
{
    /**
     * @var array<string, TcpProtocol>
     */
    private array $protocols = [];

    public function __construct(
        ?AdapterRegistry $adapters = null,
        ?callable $wonlexStateProvider = null,
        ?FirmwareUpgradeStore $firmwareUpgrades = null,
        ActiveAlarms $alarms = new ActiveAlarms(),
    ) {
        $adapters ??= new AdapterRegistry();
        $eventDecoder = new DeviceEventDecoder();

        $this->register(new WonlexTcpProtocol(
            $adapters->require('wonlex-json'),
            $eventDecoder,
            $wonlexStateProvider
        ));
        $this->register(new VivistarTcpProtocol($adapters->require('vivistar-iw'), $eventDecoder));
        $this->register(new FourPTouchTcpProtocol($adapters->require('four-p-touch'), $eventDecoder));
        $this->register(new PillDispenserTcpProtocol(
            $adapters->require('zayata-m228'),
            $eventDecoder,
            $firmwareUpgrades,
            $alarms,
        ));
    }

    public function register(TcpProtocol $protocol): void
    {
        $this->protocols[$protocol->protocol()] = $protocol;
    }

    public function get(string $protocol): ?TcpProtocol
    {
        return $this->protocols[$protocol] ?? null;
    }

    /**
     * @return array<string, TcpProtocol>
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

        foreach ($this->protocols as $tcpProtocol) {
            $metadata = $tcpProtocol->commandMetadata($bytes);
            if ($metadata !== null) {
                return $metadata;
            }
        }

        return null;
    }
}
