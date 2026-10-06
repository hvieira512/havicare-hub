<?php

declare(strict_types=1);

/**
 * Abre N streams de inquilino em simultâneo, com `Authorization` em vez de bilhete (um pedido
 * por ligação). Corre-se na própria máquina, contra `127.0.0.1`; o servidor observa-se de fora.
 *
 * Uso:
 *   php simulator/stream-stress-probe.php --url=http://127.0.0.1:8091 \
 *     --user=hitcare-1001 --password=… --connections=500 --step=50 --hold=20
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;

$options = getopt('', [
    'url::',
    'user::',
    'password::',
    'connections::',
    'step::',
    'hold::',
    'channels::',
]);

$url = rtrim((string)($options['url'] ?? 'http://127.0.0.1:8091'), '/');
$user = (string)($options['user'] ?? '');
$password = (string)($options['password'] ?? '');
$target = max(1, (int)($options['connections'] ?? 100));
$step = max(1, (int)($options['step'] ?? 25));
$hold = max(1, (int)($options['hold'] ?? 20));
$channels = (string)($options['channels'] ?? 'telemetry,events,status');

if ($user === '' || $password === '') {
    fwrite(STDERR, "--user e --password são obrigatórios\n");
    exit(1);
}

$loop = Loop::get();
$browser = (new Browser())
    // Sem tempo limite: um stream é uma resposta que nunca acaba.
    ->withTimeout(false)
    // As respostas não-2xx resolvem em vez de rejeitar: o `503` do teto é o servidor a
    // funcionar, e não uma falha de ligação.
    ->withRejectErrorResponse(false);

$state = [
    'open' => 0,
    'frames' => 0,
    'bytes' => 0,
    'refused' => 0,
    'failed' => 0,
    'closed' => 0,
];
/** @var list<ReadableStreamInterface> $bodies */
$bodies = [];

$stamp = static fn(): string => date('H:i:s');

$report = static function (string $label) use (&$state, $stamp): void {
    printf(
        "[%s] %-12s abertos=%-6d frames=%-8d KB=%-9.1f recusados=%-5d falhados=%-5d fechados=%d\n",
        $stamp(),
        $label,
        $state['open'],
        $state['frames'],
        $state['bytes'] / 1024,
        $state['refused'],
        $state['failed'],
        $state['closed'],
    );
};

echo "a autenticar como {$user} em {$url}\n";

$browser->post(
    $url . '/api/auth/login',
    ['Content-Type' => 'application/json'],
    json_encode(['username' => $user, 'password' => $password], JSON_THROW_ON_ERROR)
)->then(
    static function (ResponseInterface $response) use (
        $browser,
        $url,
        $channels,
        $target,
        $step,
        $hold,
        $loop,
        &$state,
        &$bodies,
        $report,
        $stamp
    ): void {
        $token = (string)(json_decode((string)$response->getBody(), true)['token']['access_token'] ?? '');
        if ($token === '') {
            fwrite(STDERR, "login sem token: " . (string)$response->getBody() . "\n");
            exit(1);
        }
        echo "autenticado; a abrir até {$target} streams em passos de {$step}\n";

        $openOne = static function () use (
            $browser,
            $url,
            $channels,
            $token,
            &$state,
            &$bodies
        ): void {
            $browser->requestStreaming(
                'GET',
                $url . '/api/stream?channels=' . rawurlencode($channels),
                ['Authorization' => 'Bearer ' . $token, 'Accept' => 'text/event-stream']
            )->then(
                static function (ResponseInterface $response) use (&$state, &$bodies): void {
                    if ($response->getStatusCode() !== 200) {
                        // O 503 é o teto a funcionar, e não uma falha: conta-se à parte.
                        $state[$response->getStatusCode() === 503 ? 'refused' : 'failed']++;
                        return;
                    }

                    $body = $response->getBody();
                    if (!$body instanceof ReadableStreamInterface) {
                        $state['failed']++;
                        return;
                    }

                    $state['open']++;
                    $bodies[] = $body;

                    $body->on('data', static function (string $chunk) use (&$state): void {
                        $state['bytes'] += strlen($chunk);
                        $state['frames'] += substr_count($chunk, "\n\n");
                    });
                    $body->on('close', static function () use (&$state): void {
                        $state['open']--;
                        $state['closed']++;
                    });
                },
                static function (\Throwable $e) use (&$state): void {
                    $state['failed']++;
                    if ($state['failed'] <= 3) {
                        fwrite(STDERR, "  falha ao abrir: {$e->getMessage()}\n");
                    }
                }
            );
        };

        $requested = 0;
        $ramp = null;
        $ramp = $loop->addPeriodicTimer(1.0, static function () use (
            &$requested,
            $target,
            $step,
            $openOne,
            $report,
            $loop,
            &$ramp,
            $hold,
            &$state,
            &$bodies,
            $stamp
        ): void {
            $batch = min($step, $target - $requested);
            for ($i = 0; $i < $batch; $i++) {
                $openOne();
                $requested++;
            }
            $report("pedidos={$requested}");

            if ($requested < $target) {
                return;
            }

            $loop->cancelTimer($ramp);
            printf("[%s] rampa completa; a manter %d segundos\n", $stamp(), $hold);

            $ticks = 0;
            $loop->addPeriodicTimer(5.0, static function ($timer) use (
                &$ticks,
                $hold,
                $report,
                $loop,
                &$bodies,
                $stamp
            ): void {
                $report('a manter');
                if ((++$ticks * 5) < $hold) {
                    return;
                }

                $loop->cancelTimer($timer);
                printf("[%s] a fechar tudo\n", $stamp());
                foreach ($bodies as $body) {
                    $body->close();
                }
                $report('final');
                $loop->stop();
            });
        });
    },
    static function (\Throwable $e): void {
        fwrite(STDERR, "login falhou: {$e->getMessage()}\n");
        exit(1);
    }
);

$loop->run();
