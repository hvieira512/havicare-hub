<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\DeviceService;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

final class DeviceController
{
    /** Uma rajada de escritas colapsa num envio só depois deste atraso. */
    private const STREAM_COALESCE_SECONDS = 0.25;

    /** Apanha escritas feitas fora deste processo, e mantém o socket quente. */
    private const STREAM_FALLBACK_SECONDS = 15;

    public function __construct(
        private DeviceService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(array $params, ServerRequestInterface $request): Response
    {
        $auth = RequestContext::auth($request);

        return $this->json->respond($this->service->list((string)$request->getUri()->getQuery(), $auth, RequestContext::baseUrl($request)));
    }

    public function show(array $params, ServerRequestInterface $request): Response
    {
        $auth = RequestContext::auth($request);
        $result = $this->service->show($params['imei'], $auth, RequestContext::baseUrl($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function stream(array $params, ServerRequestInterface $request): Response
    {
        $imei = $params['imei'];
        $auth = RequestContext::auth($request);

        $snapshot = $this->service->recent($imei, $auth);
        if (isset($snapshot['error'])) {
            return $this->json->respond($snapshot, 404);
        }

        $loop = Loop::get();
        $stream = new ThroughStream();
        $lastPayload = null;

        $send = function (string $event) use ($imei, $auth, $stream, &$lastPayload): void {
            if (!$stream->isWritable()) {
                return;
            }

            $data = $this->service->recent($imei, $auth);
            if (isset($data['error'])) {
                return;
            }

            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === $lastPayload) {
                // Nada mudou: mantém a ligação viva sem obrigar o cliente a redesenhar o
                // mesmo histórico.
                $stream->write(": keep-alive\n\n");
                return;
            }

            $lastPayload = $payload;
            $stream->write("event: {$event}\ndata: {$payload}\n\n");
        };

        $loop->futureTick(static function () use ($send): void {
            $send('snapshot');
        });

        // O store anuncia as suas próprias escritas, e por isso não há nada a sondar. Uma
        // rajada -- uma pulseira a anunciar um toque durante 30 segundos, ou um comando a
        // percorrer o seu ciclo de vida -- colapsa num envio só.
        $flushTimer = null;
        $unsubscribe = $this->service->updates()->subscribe(
            $imei,
            static function () use ($loop, $send, &$flushTimer): void {
                if ($flushTimer !== null) {
                    return;
                }
                $flushTimer = $loop->addTimer(self::STREAM_COALESCE_SECONDS, static function () use ($send, &$flushTimer): void {
                    $flushTimer = null;
                    $send('update');
                });
            }
        );

        // Rede de segurança para escritas que este processo não consegue observar, como um
        // script de linha de comandos a tocar no store, e serve de keep-alive do SSE.
        $timer = $loop->addPeriodicTimer(self::STREAM_FALLBACK_SECONDS, static function () use ($send): void {
            $send('update');
        });

        $stream->on('close', static function () use ($timer, $loop, $unsubscribe, &$flushTimer): void {
            $loop->cancelTimer($timer);
            // Sem isto, uma rajada a chegar quando o cliente se desliga deixava este
            // temporizador a segurar a closure até disparar para nada.
            if ($flushTimer !== null) {
                $loop->cancelTimer($flushTimer);
                $flushTimer = null;
            }
            $unsubscribe();
        });

        return new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $stream);
    }

    public function requestFeature(array $params, ServerRequestInterface $request): Response
    {
        $auth = RequestContext::auth($request);
        $result = $this->service->requestFeature($params['imei'], RequestContext::requestBody($request), $auth, RequestContext::requestId($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function links(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->links($params['imei'], RequestContext::auth($request));
        return $this->json->respond($result, $this->status->map($result));
    }

    public function createLink(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->createLink($params['imei'], $params['linkedImei'], RequestContext::auth($request));
        return $this->json->respond($result, $this->status->map($result, 201));
    }

    public function deleteLink(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->deleteLink($params['imei'], $params['linkedImei'], RequestContext::auth($request));
        return $this->json->respond($result, $this->status->map($result));
    }

    public function patchAssociation(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->patchAssociation($params['imei'], RequestContext::requestBody($request), RequestContext::auth($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function deleteAssociation(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->deleteAssociation($params['imei'], RequestContext::auth($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function commandStatus(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->commandStatus($params['id'], RequestContext::auth($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function create(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->create(RequestContext::requestBody($request));

        return $this->json->respond($result, $this->status->map($result, 201));
    }

    public function update(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->update($params['imei'], RequestContext::requestBody($request), RequestContext::auth($request), RequestContext::requestId($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function updateConfigurations(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->updateConfigurations($params['imei'], RequestContext::requestBody($request), RequestContext::auth($request), RequestContext::requestId($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function delete(array $params): Response
    {
        return $this->json->respond($this->service->delete($params['imei']));
    }

    public function recent(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->recent($params['imei'], RequestContext::auth($request));

        return $this->json->respond($result, $this->status->map($result));
    }
}
