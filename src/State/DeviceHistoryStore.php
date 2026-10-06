<?php

declare(strict_types=1);

namespace Hub\State;

/** A leitura do histórico e o aviso de que ele mudou. Quem escreve nele é o `DeviceReportStore`. */
interface DeviceHistoryStore
{
    /**
     * Com `$sinceSeq` maior que zero, só o que entrou depois desse número de ordem.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $imei, string $list, int $sinceSeq = 0): array;

    /** O número de ordem da entrada mais recente, para o cliente saber onde ficou. */
    public function latestSequence(string $imei, string $list): int;

    /** Quantas entradas o histórico guarda por lista. */
    public function historyLimit(): int;

    /**
     * Os streams subscrevem aqui para saber quando o histórico de um dispositivo muda. Vive no
     * contrato para não haver um notificador injectado à parte que seja a instância errada.
     */
    public function updates(): DeviceUpdateNotifier;
}
