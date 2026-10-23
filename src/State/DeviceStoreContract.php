<?php

declare(strict_types=1);

namespace Hub\State;

/** O que a ingestão pede ao `DeviceStore`; o resto lê-se directamente do store que o guarda. */
interface DeviceStoreContract extends
    DeviceReportStore,
    DeviceCommandLog
{
    /**
     * Com `$sinceSeq` maior que zero, só o que entrou depois desse número de ordem.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $imei, string $list, int $sinceSeq = 0): array;
}
