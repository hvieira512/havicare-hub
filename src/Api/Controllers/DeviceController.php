<?php

declare(strict_types=1);

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
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
    ) {
    }

    /** @param array<string, string> $params */
    public function list(array $params, ServerRequestInterface $request): Response
    {
        $auth = RequestContext::auth($request);

        return $this->json->result($this->service->list((string)$request->getUri()->getQuery(), $auth, RequestContext::baseUrl($request)));
    }

    /** @param array<string, string> $params */
    public function show(array $params, ServerRequestInterface $request): Response
    {
        $auth = RequestContext::auth($request);

        return $this->json->result($this->service->show($params['imei'], $auth, RequestContext::baseUrl($request)));
    }

    /** @param array<string, string> $params */
    public function stream(array $params, ServerRequestInterface $request): Response
    {
        $imei = $params['imei'];
        $auth = RequestContext::auth($request);

        $snapshot = $this->service->recent($imei, $auth);
        if (isset($snapshot['error'])) {
            return $this->json->result($snapshot);
        }

        $loop = Loop::get();
        $stream = new ThroughStream();

        // Contrapressão: com o `write()` a devolver `false`, não se escreve nem se lê o `recent()` até
        // ao `drain`, senão um cliente lento faz o buffer crescer até rebentar a memória do processo.
        $blocked = false;
        $stream->on('drain', static function () use (&$blocked): void {
            $blocked = false;
        });

        // O cursor deste cliente, por lista: o instantâneo leva o histórico todo, e as actualizações
        // só o que entrou depois.
        $cursor = ['telemetry' => 0, 'events' => 0];
        $lastCommands = null;

        $send = function (string $event) use ($imei, $auth, $stream, &$cursor, &$lastCommands, &$blocked): void {
            if (!$stream->isWritable() || $blocked) {
                return;
            }

            $data = $this->service->recent($imei, $auth, $cursor);
            if (isset($data['error'])) {
                return;
            }

            $cursor = [
                'telemetry' => (int)($data['cursor']['telemetry'] ?? 0),
                'events' => (int)($data['cursor']['events'] ?? 0),
            ];
            unset($data['cursor']);

            // Os comandos vão sempre inteiros porque mudam de estado, e é a comparação deles que decide
            // se uma actualização sem linhas novas tem algo a dizer.
            $commands = json_encode($data['commands'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hasNewEntries = $data['telemetry'] !== [] || $data['events'] !== [];
            if (!$hasNewEntries && $commands === $lastCommands) {
                // Nada mudou: mantém a ligação viva sem obrigar o cliente a redesenhar o
                // mesmo histórico.
                $blocked = $stream->write(": keep-alive\n\n") === false;
                return;
            }

            $lastCommands = $commands;
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // O `false` do `write()` não quer dizer que o payload se perdeu: ficou aceite no
            // buffer, e por isso o cursor avança na mesma.
            $blocked = $stream->write("event: {$event}\ndata: {$payload}\n\n") === false;
        };

        $loop->futureTick(static function () use ($send): void {
            $send('snapshot');
        });

        // O store anuncia as suas próprias escritas, e uma rajada colapsa num envio só.
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
            // O temporizador da rajada não pode ficar a segurar a closure depois de o cliente sair.
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

    /** @param array<string, string> $params */
    public function requestFeature(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->requestFeature($params['imei'], $payload, RequestContext::auth($request), RequestContext::requestId($request)));
    }

    /** @param array<string, string> $params */
    public function links(array $params, ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->links($params['imei'], RequestContext::auth($request)));
    }

    /**
     * Em cru, para o estado de sucesso ser o que a rota declara.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    public function createLink(array $params, ServerRequestInterface $request): array
    {
        return $this->service->createLink($params['imei'], $params['linkedImei'], RequestContext::auth($request));
    }

    /** @param array<string, string> $params */
    public function deleteLink(array $params, ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->deleteLink($params['imei'], $params['linkedImei'], RequestContext::auth($request)));
    }

    /** @param array<string, string> $params */
    public function patchAssociation(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->patchAssociation($params['imei'], $payload, RequestContext::auth($request)));
    }

    /** @param array<string, string> $params */
    public function deleteAssociation(array $params, ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->deleteAssociation($params['imei'], RequestContext::auth($request)));
    }

    /** @param array<string, string> $params */
    public function commandStatus(array $params, ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->commandStatus($params['id'], RequestContext::auth($request)));
    }

    /**
     * Em cru, para o estado de sucesso ser o que a rota declara.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    public function create(array $params, ServerRequestInterface $request): array
    {
        $payload = RequestContext::jsonBody($request);

        return $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->create($payload, RequestContext::auth($request));
    }

    /** @param array<string, string> $params */
    public function update(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->update($params['imei'], $payload, RequestContext::auth($request), RequestContext::requestId($request)));
    }

    /** @param array<string, string> $params */
    public function updateConfigurations(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->updateConfigurations($params['imei'], $payload, RequestContext::auth($request), RequestContext::requestId($request)));
    }

    /** @param array<string, string> $params */
    public function delete(array $params, ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->delete($params['imei'], RequestContext::auth($request)));
    }
}
