<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

use Hub\Device\TelemetryEnvelope;

abstract class TcpProtocol
{
    public function __construct(
        protected readonly DeviceAdapterInterface $adapter,
        protected readonly DeviceEventDecoder $eventDecoder,
    ) {
    }

    public function protocol(): string
    {
        return $this->adapter->protocol();
    }

    public function handleIncoming(DeviceSession $session, string $raw): ?TcpMessage
    {
        $decoded = $this->adapter->decodeIncoming($raw, ['session' => $session->identityContext()]);
        if (!is_array($decoded)) {
            return null;
        }

        $telemetry = [];
        foreach ($this->eventDecoder->decode($session, $decoded) as $event) {
            $payload = TelemetryEnvelope::for(
                (string)$event['feature'],
                $session->imei,
                ['supplier' => $session->supplier, 'model' => $session->model, 'commercialName' => $session->commercialName],
                $session->protocol,
                (string)$event['nativeType'],
                $event['value'],
            );
            if (is_string($decoded['occurredAt'] ?? null)) {
                $payload['occurredAt'] = $decoded['occurredAt'];
            }
            if (is_array($event['extra'] ?? null) && $event['extra'] !== []) {
                $payload['extra'] = $event['extra'];
            }
            $telemetry[] = $payload;
        }

        return new TcpMessage(
            decoded: $decoded,
            telemetry: $telemetry,
            responses: $this->responsesForDecoded($session, $decoded),
        );
    }

    /**
     * Se esta trama é o aparelho a dizer que aceitou ou recusou uma configuração; `null` é
     * «não disse», e não «recusou». Por omissão, nenhuma trama comenta configuração.
     *
     * @param array<string, mixed> $decoded
     */
    public function replyAccepted(array $decoded): ?bool
    {
        return null;
    }

    /**
     * O ident e o ref com que a resposta a este downlink se correlaciona; vazio num protocolo que
     * não correlaciona assim.
     *
     * @return array{ident?: string|int, ref?: string}
     */
    public function downlinkCorrelation(string $bytes): array
    {
        return [];
    }

    /** Se a resposta ecoa o ident do downlink; quando não, vale a do mesmo tipo que estiver pendente. */
    public function echoesReplyIdent(): bool
    {
        return true;
    }

    /** @return array{nativeType?: string, protocol?: string, ident?: string|int}|null */
    public function commandMetadata(string $bytes): ?array
    {
        $decoded = $this->adapter->decodeIncoming($bytes);
        if (!is_array($decoded)) {
            return null;
        }

        $metadata = array_filter([
            'nativeType' => (string)($decoded['type'] ?? ''),
            'protocol' => $this->protocol(),
            'ident' => $decoded['ident'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $metadata !== [] ? $metadata : null;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, TcpResponse>
     */
    abstract protected function responsesForDecoded(DeviceSession $session, array $decoded): array;
}
