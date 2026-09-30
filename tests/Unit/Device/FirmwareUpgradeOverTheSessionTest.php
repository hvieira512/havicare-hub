<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Device\Firmware\FirmwareUpgrade;
use Hub\Device\Firmware\FirmwareUpgradeStore;
use Hub\Device\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Hub\PillFakeConnection;

/**
 * A transferência sai pela sessão do aparelho, que é a única ligação que existe: ele é que
 * liga ao hub. Cada trama que ele manda é a oportunidade de enviar o pacote seguinte.
 */
final class FirmwareUpgradeOverTheSessionTest extends TestCase
{
    private const IMEI = '869243062262262';
    private const SIZE = 600;

    public function testTheRequestLeavesOnTheNextHeartbeat(): void
    {
        $store = $this->store(['status' => 'requested', 'offset' => 0]);
        $sent = $this->respondTo($store, 0x02, 0);

        self::assertSame('upgrade_start', $sent['type']);
        self::assertSame(self::SIZE, unpack('V', substr($sent['body'], 0, 4))[1]);
        self::assertSame('starting', $store->state['status']);
    }

    public function testTheFirstChunkLeavesOnTheStartAcknowledgement(): void
    {
        $store = $this->store(['status' => 'starting', 'offset' => 0]);
        $sent = $this->respondTo($store, 0x8E, 0);

        self::assertSame('upgrade_data', $sent['type']);
        self::assertSame(0, unpack('V', substr($sent['body'], 0, 4))[1]);
        self::assertSame(FirmwareUpgrade::CHUNK, strlen($sent['body']) - 4);
    }

    /** Um `Status` diferente de zero pára tudo, e o aparelho deixa de receber pacotes. */
    public function testARefusalStopsTheTransfer(): void
    {
        $store = $this->store(['status' => 'sending', 'offset' => 252]);
        $protocol = new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder(), $store);

        $message = $protocol->handleIncoming($this->session(), $this->frame(0x8F, 0x08));

        self::assertSame([], $message?->responses);
        self::assertSame('failed', $store->state['status']);
        self::assertSame(0x08, $store->state['error']);
    }

    /** Sem transferência pedida, o heartbeat continua a ser confirmado como sempre. */
    public function testWithoutAnUpgradeTheHeartbeatIsStillAcknowledged(): void
    {
        $protocol = new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder());

        $message = $protocol->handleIncoming($this->session(), $this->frame(0x02, 0));

        self::assertCount(1, $message?->responses ?? []);
        $ack = (new PillDispenserAdapter())->decodeIncoming($message->responses[0]->bytes);
        self::assertSame('heartbeat_ack', $ack['type']);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{type: string, body: string}
     */
    private function respondTo(FirmwareUpgradeStore $store, int $packetType, int $status): array
    {
        $protocol = new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder(), $store);
        $message = $protocol->handleIncoming($this->session(), $this->frame($packetType, $status));

        self::assertCount(1, $message?->responses ?? [], 'a transferência tem de mandar um pacote');
        $bytes = $message->responses[0]->bytes;
        $decoded = (new PillDispenserAdapter())->decodeIncoming($bytes);

        return [
            'type' => (string)$decoded['type'],
            // O corpo começa a seguir ao cabeçalho e acaba antes do CRC de dois bytes.
            'body' => substr($bytes, 20, -2),
        ];
    }

    private function frame(int $packetType, int $status): string
    {
        return (new PillDispenserAdapter())->encodeOutgoing([
            'packetType' => $packetType,
            'imei' => self::IMEI,
            'status' => $status,
            'serial' => 7,
            'tlv' => [],
        ]);
    }

    /** @param array<string, mixed> $state */
    private function store(array $state): FirmwareUpgradeStore
    {
        $path = tempnam(sys_get_temp_dir(), 'fw');
        file_put_contents((string)$path, str_repeat('f', self::SIZE));

        return new class ($state + [
            'path' => $path,
            'size' => self::SIZE,
            'checksum' => 123456,
            'timeout' => 1800,
        ]) implements FirmwareUpgradeStore {
            /** @param array<string, mixed> $state */
            public function __construct(public array $state)
            {
            }

            public function load(string $imei): ?array
            {
                return $this->state;
            }

            public function save(string $imei, array $state): void
            {
                $this->state = $state;
            }
        };
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            self::IMEI,
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
