<?php

namespace Hub\Device\Tcp;

use Hub\Device\DeviceEventDecoder;
use Hub\Protocol\AdapterRegistry;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use Hub\Device\Tcp\Supplier\FourPTouch\FourPTouchTcpProtocol;
use Hub\Device\Tcp\Supplier\Vivistar\VivistarTcpProtocol;
use Hub\Device\Tcp\Supplier\Wonlex\WonlexTcpProtocol;
use Hub\Device\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;

/**
 * Os protocolos que falam TCP com o hub, indexados pelo nome do protocolo.
 *
 * Chamou-se «watch» enquanto os relógios eram os únicos aparelhos a ligar-se à porta TCP. O
 * dispensador M228 passou a ser o segundo, e o nome deixou de descrever o que a camada faz: o
 * que estes quatro têm em comum não é serem relógios, é entrarem pelo mesmo socket.
 */
final class TcpProtocolRegistry
{
    /**
     * @var array<string, TcpProtocolInterface>
     */
    private array $protocols = [];

    public function __construct(
        ?AdapterRegistry $adapters = null,
        ?DeviceEventDecoder $eventDecoder = null,
        ?callable $wonlexStateProvider = null,
    ) {
        $adapters ??= new AdapterRegistry();
        $eventDecoder ??= new DeviceEventDecoder();

        $this->register(new WonlexTcpProtocol(
            $adapters->get('wonlex-json') ?? new WonlexAdapter(),
            $eventDecoder,
            $wonlexStateProvider
        ));
        $this->register(new VivistarTcpProtocol($adapters->get('vivistar-iw') ?? new VivistarAdapter(), $eventDecoder));
        $this->register(new FourPTouchTcpProtocol($adapters->get('four-p-touch') ?? new FourPTouchAdapter(), $eventDecoder));
        $this->register(new PillDispenserTcpProtocol($adapters->get('zayata-m228') ?? new PillDispenserAdapter(), $eventDecoder));
    }

    public function register(TcpProtocolInterface $protocol): void
    {
        $this->protocols[$protocol->protocol()] = $protocol;
    }

    public function get(string $protocol): ?TcpProtocolInterface
    {
        return $this->protocols[$protocol] ?? null;
    }

    /**
     * @return array<string, TcpProtocolInterface>
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
