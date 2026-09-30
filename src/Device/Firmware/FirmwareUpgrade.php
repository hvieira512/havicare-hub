<?php

declare(strict_types=1);

namespace Hub\Device\Firmware;

/**
 * A transferência de firmware do dispensador, pacote a pacote.
 *
 * O aparelho é que liga ao hub, por isso tudo sai pela sessão dele: manda-se o arranque
 * (`0x0E`), e cada pedaço (`0x0F`) só sai depois de o anterior ser confirmado. Quem sequencia
 * é o offset no corpo — os campos de subpacote do cabeçalho ficam a zero, como a secção dos
 * modos de interacção manda para um pedido partido em vários pacotes.
 *
 * Sem estado guardado aqui: recebe o que sabe, devolve o que sai e o que passa a saber.
 */
final class FirmwareUpgrade
{
    /**
     * Os 256 são do **corpo de aplicação**, não da trama: perguntámo-lo com estas palavras e
     * o fornecedor respondeu «this value is correct».
     */
    public const MAX_BODY = 256;

    /** Os quatro primeiros bytes do corpo são o offset. */
    public const CHUNK = self::MAX_BODY - 4;

    public const START = 0x0E;
    public const DATA = 0x0F;

    /**
     * A tabela do documento dá `0x8D` como resposta ao arranque e a secção 26 dá `0x8E`. O
     * fornecedor disse que corrige o documento; até lá valem as duas.
     */
    private const START_REPLIES = ['upgrade_start_ack', 'discover_event_ack'];

    public static function startBody(int $size, int $checksum, int $timeoutSeconds): string
    {
        return pack('V', $size) . pack('V', $checksum) . pack('v', $timeoutSeconds) . str_repeat("\x00", 10);
    }

    public static function dataBody(int $offset, string $chunk): string
    {
        return pack('V', $offset) . $chunk;
    }

    /** A soma acumulada dos bytes do ficheiro, que é o que o `0x0E` leva como código. */
    public static function checksum(string $firmware): int
    {
        $sum = 0;
        foreach (unpack('C*', $firmware) ?: [] as $byte) {
            $sum += $byte;
        }

        return $sum;
    }

    /**
     * O passo seguinte da transferência.
     *
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, packetType: int|null, body: string|null}
     */
    public static function advance(array $state, string $firmware, string $replyType, int $frameStatus): array
    {
        $status = (string)($state['status'] ?? '');
        $offset = (int)($state['offset'] ?? 0);
        $size = (int)($state['size'] ?? 0);

        // Uma ligação nova a meio da transferência é uma interrupção, e o fornecedor disse
        // que a seguinte recomeça do princípio: o offset guardado deixou de valer.
        if ($replyType === 'register' && in_array($status, ['starting', 'sending', 'finishing'], true)) {
            return self::step(['status' => 'requested', 'offset' => 0] + $state, null, null);
        }

        if ($status === 'requested') {
            return self::step(
                ['status' => 'starting'] + $state,
                self::START,
                self::startBody($size, (int)($state['checksum'] ?? 0), (int)($state['timeout'] ?? 0)),
            );
        }

        $expected = $status === 'starting' ? self::START_REPLIES : ['upgrade_data_ack'];
        if (!in_array($replyType, $expected, true) || !in_array($status, ['starting', 'sending', 'finishing'], true)) {
            return self::step($state, null, null);
        }

        // O corpo das respostas é vazio: quem diz se pegou é o `Status` do cabeçalho.
        if ($frameStatus !== 0) {
            return self::step(['status' => 'failed', 'error' => $frameStatus] + $state, null, null);
        }

        if ($status === 'finishing') {
            return self::step(['status' => 'done'] + $state, null, null);
        }

        if ($offset >= $size) {
            return self::step(['status' => 'finishing'] + $state, self::DATA, self::dataBody($size, ''));
        }

        $chunk = substr($firmware, $offset, self::CHUNK);

        return self::step(
            ['status' => 'sending', 'offset' => $offset + strlen($chunk)] + $state,
            self::DATA,
            self::dataBody($offset, $chunk),
        );
    }

    /**
     * O número de série anda um por cada pacote que sai, como a especificação pede, e dá a
     * volta nos dois bytes que o campo tem.
     *
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, packetType: int|null, body: string|null}
     */
    private static function step(array $state, ?int $packetType, ?string $body): array
    {
        if ($body !== null) {
            $state = ['serial' => ((int)($state['serial'] ?? 0) + 1) % 65536] + $state;
        }

        return ['state' => $state, 'packetType' => $packetType, 'body' => $body];
    }
}
