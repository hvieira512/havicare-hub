<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Device\MessageFanout;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\DashboardHttpTestCase;

/**
 * O orçamento de memória de um stream aberto.
 *
 * Existe porque o teto de ligações não é um número escolhido a gosto: é o orçamento dividido
 * pelo custo de uma ligação. Sem esta medição presa por um teste, uma alteração que engorde
 * uma ligação move o teto sem ninguém dar por isso.
 */
final class DashboardStreamMemoryTest extends DashboardHttpTestCase
{
    /** Quantas ligações a medição abre. Alto o suficiente para o custo fixo não dominar. */
    private const CONNECTIONS = 50;

    public function testAnIdleStreamCostsLessThanTheBudgetPerConnection(): void
    {
        [$server] = $this->makeServerWithDatabase(
            maxOpenStreams: 1000,
            maxOpenStreamsPerUser: 1000
        );
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        gc_collect_cycles();
        $before = memory_get_usage();

        $streams = [];
        for ($i = 0; $i < self::CONNECTIONS; $i++) {
            $streams[] = $this->open($server, $token);
        }

        gc_collect_cycles();
        $perConnection = (memory_get_usage() - $before) / self::CONNECTIONS;

        // Medido: 19,8 KB a 50 ligações e 15,4 KB a 200 -- a diferença é o custo fixo a
        // amortizar. O teto está a 32 KB, que dá folga sem deixar passar uma alteração que
        // engorde a ligação. O socket em si é orçamento do kernel, não deste número.
        self::assertLessThan(
            32 * 1024,
            $perConnection,
            sprintf('uma ligação inerte custa %.0f bytes de heap', $perConnection)
        );

        foreach ($streams as $stream) {
            $stream->getBody()->close();
        }
    }

    /**
     * O pior caso, que é o que dimensiona o teto: um cliente que parou de ler e tem a fila
     * cheia. É o produto disto pelo teto de ligações que define o orçamento do processo.
     */
    public function testAStalledStreamWithAFullQueueStaysWithinTheBudget(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase(
            maxOpenStreams: 1000,
            maxOpenStreamsPerUser: 1000
        );
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $streams = [];
        for ($i = 0; $i < self::CONNECTIONS; $i++) {
            $response = $this->open($server, $token);
            // Um separador em segundo plano faz exactamente isto: deixa de drenar.
            $response->getBody()->pause();
            $streams[] = $response;
        }

        gc_collect_cycles();
        $before = memory_get_usage();

        // Bem mais do que o limite da fila, para provar que ela trava em vez de crescer.
        $payload = json_encode([
            'type' => 'heart_rate',
            'occurredAt' => '2026-09-01T10:35:10Z',
            'device' => ['id' => '861265061009822', 'supplier' => 'Vivistar', 'model' => 'L08 Pro'],
            'data' => ['bpm' => 74],
            'source' => ['protocol' => 'vivistar-iw', 'nativeType' => 'AP49'],
        ], JSON_THROW_ON_ERROR);

        for ($round = 0; $round < 600; $round++) {
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'telemetry'),
                'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
                $payload,
            );
        }

        gc_collect_cycles();
        $perConnection = (memory_get_usage() - $before) / self::CONNECTIONS;

        // Medido: 111 KB, que são os 256 frames da fila a ~440 bytes cada. É este o número que
        // dimensiona o teto de ligações -- 2000 × ~128 KB de pior caso dão ~256 MB de
        // orçamento. O limite está a 192 KB para não passar a ser um teste sobre o tamanho do
        // payload.
        self::assertLessThan(
            192 * 1024,
            $perConnection,
            sprintf('uma ligação parada custa %.0f bytes de heap', $perConnection)
        );

        foreach ($streams as $stream) {
            $stream->getBody()->close();
        }
    }

    private function open(callable $server, string $token): ResponseInterface
    {
        $ticket = $server(
            (new ServerRequest('POST', '/api/auth/stream-ticket'))
                ->withHeader('Authorization', 'Bearer ' . $token)
        );
        $value = (string)(json_decode((string)$ticket->getBody(), true)['data']['ticket'] ?? '');
        self::assertNotSame('', $value);

        $response = $server(new ServerRequest('GET', '/api/stream?ticket=' . rawurlencode($value)));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        return $response;
    }
}
