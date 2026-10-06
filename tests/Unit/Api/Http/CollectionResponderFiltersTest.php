<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\CollectionResponder;
use PHPUnit\Framework\TestCase;

/**
 * O `filters.applied` e o `filters.available` saem como objecto mesmo vazios: um array PHP
 * vazio serializa como `[]`, e um cliente com tipos estritos rebenta.
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

    /** Com filtros preenchidos a forma também é objecto. */
    public function testPopulatedFiltersStayObjects(): void
    {
        $responder = new CollectionResponder();

        $response = $responder->respond([], 1, 20, ['q' => 'abc'], ['supplier' => ['MOKO']]);
        $json = json_encode($response, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"applied":{"q":"abc"}', $json);
        self::assertStringContainsString('"available":{"supplier":["MOKO"]}', $json);
    }
}
