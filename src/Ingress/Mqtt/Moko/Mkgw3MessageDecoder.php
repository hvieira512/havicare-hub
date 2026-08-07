<?php

namespace Hub\Ingress\Mqtt\Moko;

final class Mkgw3MessageDecoder implements MessageDecoder
{
    /** @return array{messageId: int, gatewayMac: string, data: mixed}|null */
    public function decode(string $payload): ?array
    {
        $message = json_decode($payload, true);
        if (!is_array($message)) {
            return null;
        }

        $messageId = filter_var($message['msg_id'] ?? null, FILTER_VALIDATE_INT);
        $gatewayMac = Topic::normalizeMac((string)($message['device_info']['mac'] ?? ''));
        if ($messageId === false || $gatewayMac === null || !in_array((int)$messageId, [3004, 3070], true)) {
            return null;
        }

        return [
            'messageId' => (int)$messageId,
            'gatewayMac' => $gatewayMac,
            'data' => $message['data'] ?? null,
            'protocol' => 'moko-mkgw3',
            'encoding' => 'json',
        ];
    }
}
