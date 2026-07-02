<?php

namespace Hub\Api\Controllers;

use Hub\Api\Auth\ApiAuthContext;
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
        $initialPayload = "event: snapshot\ndata: " . json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

        $loop->futureTick(static function () use ($stream, $initialPayload): void {
            if ($stream->isWritable()) {
                $stream->write($initialPayload);
            }
        });

        $timer = $loop->addPeriodicTimer(2, function () use ($imei, $auth, $stream): void {
            if (!$stream->isWritable()) {
                return;
            }

            $data = $this->service->recent($imei, $auth);
            if (!isset($data['error'])) {
                $stream->write("event: update\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n");
            }
        });

        $stream->on('close', static function () use ($timer, $loop): void {
            $loop->cancelTimer($timer);
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
