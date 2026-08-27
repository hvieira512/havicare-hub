<?php

namespace Hub\Domain\Capability\AlarmClock;

use Hub\Domain\Capability\CapabilityProtocolHandler;

/** O handler por fornecedor da conversão fio ↔ público do `alarm_clock`. */
interface AlarmClockHandler extends CapabilityProtocolHandler
{
    /** A chave de protocolo que este handler trata (`reminders`, `alarmClock`, ...). */
    public function nativeKey(): string;

    /** Converte o valor genérico da API no mapa `chave de protocolo => payload`. */
    public function toNative(mixed $value): array;

    /** Converte o payload pretendido do protocolo numa lista de itens públicos. */
    public function fromNative(array $desired): array;

    /** O payload pretendido por omissão, para este protocolo. */
    public function defaultValue(): mixed;
}
