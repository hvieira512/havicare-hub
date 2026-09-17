<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Http\Qinglanst;

use Hub\Ingress\Http\Qinglanst\LayoutParser;
use PHPUnit\Framework\TestCase;

/**
 * As respostas são as do `thirdparty/v2/deviceProp` para dois radares reais: o 414D74184CBF,
 * com as chaves das áreas seguidas, e o 594B3CF1B367, com buracos nelas.
 */
final class LayoutParserTest extends TestCase
{
    public function testParsesALayoutWhoseAreaNamesArriveAsAList(): void
    {
        $layout = (new LayoutParser())->parse([
            'rectangle' => '{-30,-8;30,-8;-30,20;30,20}',
            'declare_area' => '{0,2,-20,-8,-11,-8,-20,12,-11,12},{1,5,-5,-8,4,-8,-5,12,4,12},'
                . '{2,4,-31,-8,-29,-8,-31,4,-29,4},{3,6,27,-8,30,-8,27,20,30,20},'
                . '{4,7,22,15,25,15,22,25,25,25},',
            'declare_area_name' => ['2_CAMA 1', '5_Cama 2', '4_Porta', '6_Varanda', '7_Mesa'],
        ]);

        self::assertSame(
            ['x_min_dm' => -30, 'y_min_dm' => -8, 'x_max_dm' => 30, 'y_max_dm' => 20],
            $layout['room'],
        );

        // Cinco e não seis: o fabricante fecha o `declare_area` com uma vírgula pendurada.
        self::assertCount(5, $layout['areas']);
        self::assertSame(
            ['key' => 0, 'type' => 2, 'name' => 'CAMA 1', 'x_min_dm' => -20, 'y_min_dm' => -8, 'x_max_dm' => -11, 'y_max_dm' => 12],
            $layout['areas'][0],
        );
        // A porta fica fora da sala, e não é erro nenhum: o `x` vai a -31.
        self::assertSame(-31, $layout['areas'][2]['x_min_dm']);
        self::assertSame('Porta', $layout['areas'][2]['name']);
    }

    /**
     * O nome liga-se à área pela chave e nunca pela posição. Aqui as chaves são 0, 1, 3, 5 e 7
     * -- é por serem esparsas que o `json_encode` do lado do fabricante manda um objeto em vez
     * de uma lista --, e por posição a área 5 ficaria com o nome da 3.
     */
    public function testJoinsAreaNamesByKeyWhenTheKeysHaveGaps(): void
    {
        $layout = (new LayoutParser())->parse([
            'rectangle' => '{-13,-8;30,-8;-13,20;30,20}',
            'declare_area' => '{0,5,-6,-7,4,-7,-6,13,4,13},{1,4,-18,14,-10,14,-18,20,-10,20},'
                . '{3,6,29,1,31,1,29,17,31,17},{5,2,18,-7,28,-7,18,13,28,13},'
                . '{7,7,5,-8,13,-8,5,2,13,2},',
            'declare_area_name' => [
                '0' => '5_Cama 2',
                '1' => '4_Porta',
                '3' => '6_Varanda',
                '5' => '2_Cama 1',
                '7' => '7_Sofá',
            ],
        ]);

        $namesByKey = array_column($layout['areas'], 'name', 'key');

        self::assertSame(
            [0 => 'Cama 2', 1 => 'Porta', 3 => 'Varanda', 5 => 'Cama 1', 7 => 'Sofá'],
            $namesByKey,
        );
    }

    public function testFallsBackToTheKeyWhenTheAreaHasNoDeclaredName(): void
    {
        $layout = (new LayoutParser())->parse([
            'rectangle' => '{-10,-6;10,-6;-10,20;10,20}',
            'declare_area' => '{2,3,-9,-6,-1,-6,-9,-2,-1,-2},',
            'declare_area_name' => [],
        ]);

        self::assertSame('Área 2', $layout['areas'][0]['name']);
    }

    /**
     * Guardamos caixas, e não polígonos: as 63 áreas dos 13 radares em produção são todas
     * caixas alinhadas aos eixos. Uma que não seja fica de fora com o motivo, em vez de virar
     * uma caixa envolvente que desenhava uma divisão que não existe.
     */
    public function testSkipsAnAreaThatIsNotAnAxisAlignedBox(): void
    {
        $layout = (new LayoutParser())->parse([
            'rectangle' => '{-10,-6;10,-6;-10,20;10,20}',
            'declare_area' => '{0,5,-5,-5,5,-5,-5,5,5,5},{1,5,0,0,4,1,5,6,1,5},',
            'declare_area_name' => ['5_Cama', '5_Torto'],
        ]);

        self::assertCount(1, $layout['areas']);
        self::assertSame(0, $layout['areas'][0]['key']);
        self::assertSame([1 => 'not_an_axis_aligned_box'], $layout['skipped']);
    }

    /** Um radar offline responde sem `data`, e o `rectangle` pode simplesmente não vir. */
    public function testReturnsNothingWhenTheRoomRectangleIsMissing(): void
    {
        $layout = (new LayoutParser())->parse(['declare_area' => '', 'declare_area_name' => []]);

        self::assertNull($layout);
    }
}
