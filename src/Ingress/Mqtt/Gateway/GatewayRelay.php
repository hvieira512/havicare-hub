<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

/** Uma ponte que trata o que um gateway BLE reencaminha, já descodificado pelo subscritor do gateway. */
interface GatewayRelay
{
    /** @param array<string, mixed> $message */
    public static function claims(array $message): bool;

    /** @param array<string, mixed> $message */
    public function handle(string $topic, array $message): void;
}
