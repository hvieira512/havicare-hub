<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * As TAGs de texto do M228 têm comprimento fixo, e o aparelho recusa-as com outro.
 *
 * A especificação declara `STRING` com comprimento **20** para todas elas — a calibração do
 * relógio (`0xA101`), o CCID do cartão SIM (`0x8009`), o identificador do prato (`0x8105`).
 * O hub mandava o comprimento do texto: 19 bytes para `2026-09-22T09:49:28`. O aparelho
 * respondeu `011` em ambas as direcções, que é «comprimento não corresponde» — o relógio
 * nunca chegou a ser calibrado, e o CCID nunca chegou a ser lido.
 *
 * Isto apanhou-se contra o aparelho: o `0x88` de resposta à calibração veio com estado 3, e
 * uma leitura do `0x8009` tinha vindo com o mesmo estado meia hora antes.
 */
final class PillDispenserStringLengthTest extends TestCase
{
    /** O valor vai preenchido até ao comprimento que a especificação declara. */
    public function testAStringIsPaddedToItsDeclaredLength(): void
    {
        $packed = PillDispenserAdapter::packTlv([
            0xA101 => ['value' => '2026-09-22T09:49:28'],
        ]);

        // Tag(2) + Flag(1) + Length(1) + Value.
        self::assertSame(20, ord($packed[3]), 'o comprimento declarado tem de ser 20');
        self::assertSame(24, strlen($packed));
        self::assertSame(
            "2026-09-22T09:49:28\x00",
            substr($packed, 4),
            'o texto vai preenchido com zeros até aos 20 bytes',
        );
    }

    /** E volta a ler-se sem o enchimento, senão o valor trazia zeros colados. */
    public function testTheParsedValueHasNoPadding(): void
    {
        $parsed = PillDispenserAdapter::parseTlv(PillDispenserAdapter::packTlv([
            0xA101 => ['value' => '2026-09-22T09:49:28'],
        ]));

        self::assertSame('2026-09-22T09:49:28', $parsed[0xA101]['value']);
    }

    /**
     * Um pedido de leitura de uma TAG de texto reserva os mesmos 20 bytes.
     *
     * O pedido leva o valor a zeros com o comprimento certo, e é por isso que ele também
     * falhava: o `0x8009` saía com um byte reservado em vez de vinte.
     */
    public function testAReadRequestReservesTheFullStringLength(): void
    {
        $request = PillDispenserAdapter::readRequestTlv([0x8009]);

        self::assertSame(20, strlen((string)$request[0x8009]['value']));
    }

    /** Os tipos numéricos não mudam: um INT8U continua a ser um byte. */
    public function testNumericTagsKeepTheirWidth(): void
    {
        $request = PillDispenserAdapter::readRequestTlv([0x8101, 0x810B]);

        self::assertSame(1, strlen((string)$request[0x8101]['value']), 'INT8U');
        self::assertSame(2, strlen((string)$request[0x810B]['value']), 'INT16S');
    }
}
