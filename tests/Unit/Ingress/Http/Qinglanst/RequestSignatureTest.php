<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Http\Qinglanst;

use Hub\Ingress\Http\Qinglanst\RequestSignature;
use PHPUnit\Framework\TestCase;

/**
 * A assinatura de cada pedido ao fabricante.
 *
 * Os valores esperados são constantes e não recalculados aqui: o que este teste tem de apanhar
 * é uma mudança na *forma* da cadeia assinada -- a ordem, os separadores, o cardinal final --,
 * e um esperado calculado da mesma maneira que o código passaria a concordar com o erro. O
 * fabricante recusa uma assinatura errada com um 401 seco, sem dizer porquê.
 */
final class RequestSignatureTest extends TestCase
{
    private const SECRET = 'segredo';
    private const TIMESTAMP = 1700000000;

    public function testSignsASingleParameter(): void
    {
        self::assertSame(
            'A486B04402A118E69116BB642ECD01D15E21C4C2',
            RequestSignature::create(self::SECRET, self::TIMESTAMP, ['uid' => 'ABC']),
        );
    }

    /** Os pares vão por ordem alfabética e não pela ordem em que quem chama os escreveu. */
    public function testSortsTheParametersBeforeSigning(): void
    {
        $expected = '360CA6773A992FDF2458AAD19E59387C52BEF36A';

        self::assertSame(
            $expected,
            RequestSignature::create(self::SECRET, self::TIMESTAMP, ['a' => 1, 'uid' => 'ABC']),
        );
        self::assertSame(
            $expected,
            RequestSignature::create(self::SECRET, self::TIMESTAMP, ['uid' => 'ABC', 'a' => 1]),
        );
    }

    /** Sem parâmetros não há cardinal final: a cadeia acaba no timestamp. */
    public function testSignsAnEmptyParameterList(): void
    {
        self::assertSame(
            'C4250B5818A49AC24BC39D4E080C08B2858DFFEB',
            RequestSignature::create(self::SECRET, self::TIMESTAMP, []),
        );
    }
}
