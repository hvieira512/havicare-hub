<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\CollectionResponder;
use PHPUnit\Framework\TestCase;

/**
 * A forma de `filters` na resposta de uma colecção.
 *
 * O esquema declara `filters.applied` e `filters.available` como objectos. Um array PHP vazio
 * serializa como `[]`, e um cliente com tipos estritos rebenta ao receber uma lista onde
 * esperava um objecto. As listagens sem filtros -- `/api/companies`, `/api/suppliers` --
 * passavam `[]`, e é esse o caso que isto prende.
 */
final class CollectionResponderFiltersTest extends TestCase
{
    public function testEmptyFiltersSerializeAsObjectsNotArrays(): void
    {
        $responder = new CollectionResponder();

        $response = $responder->respond([], 1, 20, [], []);
        $json = json_encode($response, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"filters":{"applied":{},"available":{}}', $json);
        self::assertStringNotContainsString('"applied":[]', $json);
        self::assertStringNotContainsString('"available":[]', $json);
    }

    /** Com filtros preenchidos a forma continua a ser objecto, como já era. */
    public function testPopulatedFiltersStayObjects(): void
    {
        $responder = new CollectionResponder();

        $response = $responder->respond([], 1, 20, ['q' => 'abc'], ['supplier' => ['MOKO']]);
        $json = json_encode($response, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"applied":{"q":"abc"}', $json);
        self::assertStringContainsString('"available":{"supplier":["MOKO"]}', $json);
    }
}
