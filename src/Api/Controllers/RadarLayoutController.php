<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\JsonResponder;
use Hub\Api\Services\RadarLayoutService;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

final class RadarLayoutController
{
    public function __construct(
        private RadarLayoutService $service,
        private JsonResponder $json,
    ) {
    }

    public function show(array $params): Response
    {
        return $this->json->result($this->service->show($params['imei']));
    }

    /**
     * Devolve a promessa em vez de esperar pela cloud do fabricante: o processo tem um event
     * loop só, e a ingestão pararia enquanto isto bloqueasse.
     */
    public function sync(array $params): PromiseInterface
    {
        return $this->service->sync($params['imei'])
            ->then(fn(array $result): Response => $this->json->result($result));
    }
}
