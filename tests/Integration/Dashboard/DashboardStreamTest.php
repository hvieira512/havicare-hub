<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use React\EventLoop\Loop;
use Tests\Support\DashboardHttpTestCase;

/**
 * O stream de eventos de um dispositivo. Corre o event loop a sério, e por isso vive à parte:
 * um temporizador esquecido aqui aparece como lentidão noutro ficheiro.
 */
final class DashboardStreamTest extends DashboardHttpTestCase
{
    public function testStreamPushesUpdatesAsTheyAreWrittenAndReleasesItsListenerOnClose(): void
    {
        [$server, , $store] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($this->streamTicket($server, $token))
        ));
        self::assertSame(200, $response->getStatusCode());

        // Um stream tem um ouvinte só.
        self::assertSame(1, $store->updates()->listenerCount());

        $frames = $this->collectSseFramesUntilUpdate($response, function () use ($store): void {
            $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 71]);
            $store->recordCommand('861265061009822', 'cmd-9', [
                'status' => 'waiting',
                'imei' => '861265061009822',
                'protocol' => 'vivistar',
                'requestId' => 'BPXL',
                'nativeType' => 'BPXL',
                'label' => 'Heart rate',
                'feature' => 'heart_rate',
                'expectedReplyTypes' => [],
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ]);
        });

        // Chegar dentro do prazo prova o caminho de push: o recurso periódico só dispara
        // depois de `STREAM_FALLBACK_SECONDS`.
        self::assertStringContainsString("event: snapshot\n", $frames);
        self::assertStringContainsString("event: update\n", $frames);

        $update = $this->decodeSseFrame(substr($frames, (int)strpos($frames, 'event: update')));
        // A rajada de escritas colapsa num frame que leva as duas.
        self::assertSame('heart_rate', $update['telemetry'][0]['type'] ?? null);
        self::assertSame('BPXL', $update['commands'][0]['requestId'] ?? null);

        $response->getBody()->close();
        self::assertSame(0, $store->updates()->listenerCount());
    }

    public function testClosingTheStreamDuringABurstLeavesNoTimersOrListeners(): void
    {
        [$server, , $store] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($this->streamTicket($server, $token))
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $store->updates()->listenerCount());

        // Agenda um envio e desliga antes de a janela de coalescência dele acabar.
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 70]);
        $response->getBody()->close();

        self::assertSame(0, $store->updates()->listenerCount());

        // O envio pendente não pode sobreviver ao fecho: corre-se o loop para além da janela
        // de coalescência para confirmar que nada ficou a segurá-lo aberto.
        $loop = Loop::get();
        $ticks = 0;
        $loop->addPeriodicTimer(0.05, static function ($timer) use ($loop, &$ticks): void {
            if (++$ticks >= 10) {
                $loop->cancelTimer($timer);
                $loop->stop();
            }
        });
        $loop->run();

        // Uma escrita depois do fecho não pode chegar a ninguém.
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 71]);
        self::assertSame(0, $store->updates()->listenerCount());
    }

    /**
     * O `EventSource` não deixa pôr cabeçalhos, e a credencial viaja no URL -- onde fica no
     * registo de acessos de qualquer proxy. Daí o bilhete valer segundos e queimar-se.
     */
    public function testAStreamTicketOpensOneStreamAndIsThenSpent(): void
    {
        [$server] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $minted = $server(
            (new ServerRequest('POST', '/api/auth/stream-ticket'))
                ->withHeader('Authorization', 'Bearer ' . $token)
        );
        self::assertSame(200, $minted->getStatusCode());

        $ticket = (string)(json_decode((string)$minted->getBody(), true)['data']['ticket'] ?? '');
        self::assertNotSame('', $ticket);

        $opened = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($ticket)
        ));
        self::assertSame(200, $opened->getStatusCode(), 'o bilhete devia abrir o stream');
        $opened->getBody()->close();

        $reused = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($ticket)
        ));
        self::assertSame(401, $reused->getStatusCode(), 'um bilhete gasto não abre nada');
    }

    /** Sem credencial nenhuma continua a não abrir: o bilhete não é uma porta lateral. */
    public function testAStreamWithoutACredentialIsStillRejected(): void
    {
        [$server] = $this->makeServerWithDatabase();

        $response = $server(new ServerRequest('GET', '/api/devices/861265061009822/stream'));

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * O token de acesso não abre nada a partir de um URL: é uma credencial de uma hora e boa
     * para a API toda, e num endereço acabaria no registo de acessos de um proxy. No
     * cabeçalho continua a valer.
     */
    public function testAnAccessTokenInTheQueryStringNoLongerAuthenticates(): void
    {
        [$server] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $viaQuery = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?access_token=' . rawurlencode($token)
        ));
        self::assertSame(401, $viaQuery->getStatusCode(), 'o URL deixou de ser um sítio para credenciais');

        $viaQueryOnANormalRoute = $server(new ServerRequest(
            'GET',
            '/api/devices?access_token=' . rawurlencode($token)
        ));
        self::assertSame(401, $viaQueryOnANormalRoute->getStatusCode());

        $viaHeader = $server(
            (new ServerRequest('GET', '/api/devices'))->withHeader('Authorization', 'Bearer ' . $token)
        );
        self::assertSame(200, $viaHeader->getStatusCode(), 'no cabeçalho continua a valer');
    }

    /**
     * Um cliente que deixa de ler não pode obrigar o servidor a guardar-lhe tudo. Sem esta
     * contrapressão o buffer crescia até rebentar o limite de memória do processo, e isso
     * derrubou a produção doze vezes em catorze dias.
     */
    public function testAStreamStopsWritingWhileTheClientIsNotDrainingAndRecoversAfterwards(): void
    {
        [$server, , $store] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($this->streamTicket($server, $token))
        ));
        self::assertSame(200, $response->getStatusCode());

        $body = $response->getBody();
        $writes = 0;
        $body->on('data', static function () use (&$writes): void {
            $writes++;
        });

        $loop = Loop::get();
        $settle = static function (float $seconds) use ($loop): void {
            $loop->addTimer($seconds, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
        };

        // Deixa sair o snapshot inicial, que é o que o cliente recebe ao ligar-se.
        $settle(0.1);
        $afterSnapshot = $writes;
        self::assertGreaterThan(0, $afterSnapshot, 'o snapshot inicial devia ter saído');

        // O cliente pára de ler. É o que um separador em segundo plano faz.
        $body->pause();

        // Uma escrita por janela de coalescência, e não quarenta de enfiada: essas colapsam
        // num envio só, e o teste passava com e sem a correcção.
        for ($round = 0; $round < 5; $round++) {
            $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 60 + $round]);
            // Um pouco acima do `DeviceController::STREAM_COALESCE_SECONDS`, que é 0.25.
            $settle(0.3);
        }

        // Uma escrita descobre a pausa; a partir daí não se escreve mais nada.
        self::assertSame(
            $afterSnapshot + 1,
            $writes,
            'com o cliente em pausa só a escrita que descobre a pausa pode sair'
        );

        // E quando ele volta a ler, o stream volta a servir.
        $body->resume();
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 99]);
        $settle(0.6);

        self::assertGreaterThan(
            $afterSnapshot + 1,
            $writes,
            'depois do drain o stream tem de voltar a escrever'
        );

        $body->close();
        self::assertSame(0, $store->updates()->listenerCount());
    }

    public function testTenantClientCanUseRecentRequestAndStreamRoutes(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate', 'location']);
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 72]);
        $store->append('861265061009822', 'events', ['type' => 'sos', 'status' => 'triggered']);
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'waiting',
            'imei' => '861265061009822',
            'protocol' => 'vivistar',
            'requestId' => 'BPXL',
            'nativeType' => 'BPXL',
            'label' => 'Heart rate',
            'feature' => 'heart_rate',
            'expectedReplyTypes' => [],
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $requestResponse = $server(new ServerRequest(
            'POST',
            '/api/devices/861265061009822/requests',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR)
        ));
        $requestBody = json_decode((string)$requestResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $requestResponse->getStatusCode(), (string)$requestResponse->getBody());
        self::assertSame('heart_rate', $requestBody['feature'] ?? null);

        $streamResponse = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($this->streamTicket($server, $token))
        ));
        self::assertSame(200, $streamResponse->getStatusCode());
        self::assertSame('text/event-stream', $streamResponse->getHeaderLine('Content-Type'));

        $snapshotFrame = $this->readSseFrame($streamResponse);
        self::assertStringContainsString("event: snapshot\n", $snapshotFrame);
        $snapshot = $this->decodeSseFrame($snapshotFrame);
        self::assertSame('heart_rate', $snapshot['telemetry'][0]['type'] ?? null);
        self::assertSame('sos', $snapshot['events'][0]['type'] ?? null);
        self::assertSame('BPXL', $snapshot['commands'][0]['requestId'] ?? null);
        self::assertArrayNotHasKey('actions', $snapshot);

        $otherStream = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009833/stream?ticket=' . rawurlencode($this->streamTicket($server, $token))
        ));
        self::assertSame(404, $otherStream->getStatusCode(), (string)$otherStream->getBody());

        $log = $this->apiLogContents();
        // O bilhete viaja no URL, e o URL é o campo que aqui se regista. Já vem gasto quando
        // a linha se escreve, mas uma credencial em claro num ficheiro de registo não se
        // justifica por ser de curta duração.
        self::assertStringContainsString('"query":"ticket=********"', $log);
        self::assertDoesNotMatchRegularExpression('/"query":"ticket=[0-9a-f]{64}"/', $log);
    }

    private function readSseFrame(\Psr\Http\Message\ResponseInterface $response): string
    {
        $body = $response->getBody();
        $frame = '';
        $loop = Loop::get();

        $body->on('data', static function (string $chunk) use (&$frame, $body, $loop): void {
            $frame .= $chunk;
            if (str_contains($frame, "\n\n")) {
                $body->close();
                $loop->stop();
            }
        });

        // O prazo tem de ser cancelado quando o frame chega primeiro. O loop é um singleton
        // partilhado pela suite: um temporizador que sobrevive a este método fica armado e já
        // fora de prazo, e mata o `run()` do teste seguinte antes de ele publicar.
        $timeout = $loop->addTimer(0.2, static function () use ($body, $loop): void {
            if (method_exists($body, 'close')) {
                $body->close();
            }
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        return $frame;
    }

    /**
     * Lê o instantâneo, corre o `$write`, e continua a ler até a actualização chegar. O prazo
     * está muito abaixo do recurso periódico do stream, e por isso um frame aqui só pode ter
     * vindo do store a anunciar a escrita.
     */
    private function collectSseFramesUntilUpdate(
        \Psr\Http\Message\ResponseInterface $response,
        callable $write
    ): string {
        $body = $response->getBody();
        $frames = '';
        $loop = Loop::get();

        $body->on('data', static function (string $chunk) use (&$frames, $loop): void {
            $frames .= $chunk;
            if (str_contains($frames, 'event: update')) {
                $loop->stop();
            }
        });

        $loop->addTimer(0.05, static function () use ($write): void {
            $write();
        });
        $timeout = $loop->addTimer(2.0, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        return $frames;
    }

    private function decodeSseFrame(string $frame): array
    {
        foreach (explode("\n", trim($frame)) as $line) {
            if (str_starts_with($line, 'data: ')) {
                return json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        self::fail('SSE frame did not contain a data line.');
    }

    /**
     * Um bilhete para abrir um stream.
     *
     * Emite-se um por ligação de propósito: o bilhete queima-se ao ser lido, e reaproveitar
     * um entre dois streams era testar uma coisa que o servidor não permite.
     */
    private function streamTicket(callable $server, string $token): string
    {
        $response = $server(
            (new ServerRequest('POST', '/api/auth/stream-ticket'))
                ->withHeader('Authorization', 'Bearer ' . $token)
        );
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $ticket = (string)(json_decode((string)$response->getBody(), true)['data']['ticket'] ?? '');
        self::assertNotSame('', $ticket);

        return $ticket;
    }
}
