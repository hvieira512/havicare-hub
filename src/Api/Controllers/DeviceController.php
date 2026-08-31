<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
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

        // O cliente ainda não drenou o que já lhe foi escrito.
        //
        // Sem isto o processo morria, e morria em produção: um radar publica cerca de vinte
        // mensagens por segundo, cada envio leva o `recent()` inteiro, e o `write()` aceita
        // tudo o que lhe derem. Quando o cliente drena mais devagar do que o dispositivo
        // produz -- um separador em segundo plano chega para isso --, o buffer do socket
        // crescia sem tecto até rebentar o limite de memória do PHP, e levava com ele todas
        // as ligações TCP e todas as subscrições MQTT do processo, para todos os clientes.
        //
        // O `write()` devolve `false` quando o consumidor pediu pausa. A partir daí não se
        // escreve mais nada -- nem se lê o `recent()`, que é a parte cara -- até ao `drain`.
        $blocked = false;
        $stream->on('drain', static function () use (&$blocked): void {
            $blocked = false;
        });

        // Onde é que este cliente já vai, por lista. O instantâneo parte do zero e leva o
        // histórico todo; a partir daí cada actualização leva só o que entrou depois.
        //
        // É isto que tira a pressão de onde ela vinha: um radar publica cerca de vinte
        // mensagens por segundo, e mandar as cem entradas de cada lista quatro vezes por
        // segundo eram umas dezenas de KB por segundo por separador aberto. Com o cursor são
        // as poucas linhas que mudaram. A contrapressão continua a ser a rede de segurança,
        // mas passa a ser bem menos precisada.
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

            // Os comandos vão sempre por inteiro porque mudam de estado, e por isso é a
            // comparação deles que decide se uma actualização sem linhas novas tem alguma
            // coisa para dizer.
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
        $payload = RequestContext::jsonBody($request);
        $result = $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->requestFeature($params['imei'], $payload, RequestContext::auth($request), RequestContext::requestId($request));

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
        $payload = RequestContext::jsonBody($request);
        $result = $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->patchAssociation($params['imei'], $payload, RequestContext::auth($request));

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
        $payload = RequestContext::jsonBody($request);
        $result = $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->create($payload);

        return $this->json->respond($result, $this->status->map($result, 201));
    }

    public function update(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);
        $result = $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->update($params['imei'], $payload, RequestContext::auth($request), RequestContext::requestId($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function updateConfigurations(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);
        $result = $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->updateConfigurations($params['imei'], $payload, RequestContext::auth($request), RequestContext::requestId($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function delete(array $params): Response
    {
        return $this->json->respond($this->service->delete($params['imei']));
    }
}
