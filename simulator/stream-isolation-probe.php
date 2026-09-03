<?php

declare(strict_types=1);

/**
 * Prova, contra uma instância a correr, que o `/api/stream` de um inquilino não leva nada de
 * outro -- nem de outra licença da mesma empresa, nem de outra empresa.
 *
 * A parte que interessa não é mostrar que o `hitcare-1001` só vê `hitcare/1001`. É mostrá-lo
 * **enquanto os outros inquilinos estão a produzir**: uma janela em que os outros estivessem
 * calados provava apenas que estavam calados. Por isso abre todos os streams ao mesmo tempo,
 * conta o que cada um recebeu, e só considera a prova válida para os pares que de facto
 * tiveram tráfego na janela.
 *
 * Uso:
 *   php simulator/stream-isolation-probe.php --url=http://127.0.0.1:8091 \
 *     --users=hitcare-1001,hitcare-2103,havicare-1 --password=… --seconds=60
 *
 * Sai com 1 se algum stream vir um par empresa+licença que não seja o seu.
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise;
use React\Stream\ReadableStreamInterface;

$options = getopt('', ['url::', 'users::', 'password::', 'seconds::', 'channels::']);

$url = rtrim((string)($options['url'] ?? 'http://127.0.0.1:8091'), '/');
$users = array_values(array_filter(array_map('trim', explode(',', (string)($options['users'] ?? '')))));
$password = (string)($options['password'] ?? '');
$seconds = max(5, (int)($options['seconds'] ?? 60));
$channels = (string)($options['channels'] ?? 'telemetry,events,status');

if ($users === [] || $password === '') {
    fwrite(STDERR, "--users e --password são obrigatórios\n");
    exit(1);
}

$loop = Loop::get();
$browser = (new Browser())->withTimeout(false)->withRejectErrorResponse(false);

/** @var array<string, array{scope: string, frames: int, seen: array<string, int>, devices: array<string, true>}> $seen */
$seen = [];
/** @var list<ReadableStreamInterface> $bodies */
$bodies = [];

echo "a autenticar " . count($users) . " inquilinos\n";

$logins = [];
foreach ($users as $user) {
    $logins[] = $browser->post(
        $url . '/api/auth/login',
        ['Content-Type' => 'application/json'],
        json_encode(['username' => $user, 'password' => $password], JSON_THROW_ON_ERROR)
    )->then(static function (ResponseInterface $response) use ($user): array {
        $token = json_decode((string)$response->getBody(), true)['token'] ?? null;
        if (!is_array($token) || ($token['access_token'] ?? '') === '') {
            fwrite(STDERR, "  {$user}: login falhou\n");
            return [];
        }

        return [
            'user' => $user,
            'token' => (string)$token['access_token'],
            // O âmbito que o próprio servidor diz ser dele. É contra isto que se compara, e
            // não contra o que o nome do utilizador sugere.
            'scope' => strtolower((string)$token['company']) . '/' . (int)$token['license_id'],
        ];
    });
}

Promise\all($logins)->then(static function (array $identities) use (
    $browser,
    $url,
    $channels,
    $seconds,
    $loop,
    &$seen,
    &$bodies
): void {
    $identities = array_values(array_filter($identities));
    if ($identities === []) {
        fwrite(STDERR, "nenhum login válido\n");
        exit(1);
    }

    foreach ($identities as $id) {
        printf("  %-16s âmbito do token: %s\n", $id['user'], $id['scope']);
        $seen[$id['user']] = ['scope' => $id['scope'], 'frames' => 0, 'seen' => [], 'devices' => []];
    }

    echo "\na abrir um stream por inquilino, e a recolher {$seconds}s\n";

    foreach ($identities as $id) {
        $browser->requestStreaming(
            'GET',
            $url . '/api/stream?channels=' . rawurlencode($channels),
            ['Authorization' => 'Bearer ' . $id['token'], 'Accept' => 'text/event-stream']
        )->then(static function (ResponseInterface $response) use ($id, &$seen, &$bodies): void {
            if ($response->getStatusCode() !== 200) {
                fwrite(STDERR, "  {$id['user']}: stream recusado ({$response->getStatusCode()})\n");
                return;
            }

            $body = $response->getBody();
            if (!$body instanceof ReadableStreamInterface) {
                return;
            }
            $bodies[] = $body;

            $buffer = '';
            $body->on('data', static function (string $chunk) use ($id, &$seen, &$buffer): void {
                $buffer .= $chunk;
                while (($end = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 2);

                    foreach (explode("\n", $frame) as $line) {
                        if (!str_starts_with($line, 'data: ')) {
                            continue;
                        }
                        $data = json_decode(substr($line, 6), true);
                        if (!is_array($data)) {
                            continue;
                        }

                        $pair = strtolower((string)($data['company'] ?? '?')) . '/' . (int)($data['licenseId'] ?? 0);
                        $seen[$id['user']]['frames']++;
                        $seen[$id['user']]['seen'][$pair] = ($seen[$id['user']]['seen'][$pair] ?? 0) + 1;
                        $seen[$id['user']]['devices'][(string)($data['deviceId'] ?? '?')] = true;
                    }
                }
            });
        });
    }

    $loop->addTimer($seconds, static function () use ($loop, &$bodies): void {
        foreach ($bodies as $body) {
            $body->close();
        }
        $loop->stop();
    });
})->then(null, static function (\Throwable $e): void {
    fwrite(STDERR, "erro: {$e->getMessage()}\n");
    exit(1);
});

$loop->run();

// ----- veredicto -----

echo "\n";
printf("%-16s %-16s %-8s %-9s %s\n", 'INQUILINO', 'ÂMBITO', 'FRAMES', 'APARELHOS', 'PARES VISTOS');
$violations = [];
$producing = [];

foreach ($seen as $user => $row) {
    $pairs = [];
    foreach ($row['seen'] as $pair => $count) {
        $pairs[] = "{$pair}({$count})";
        if ($pair !== $row['scope']) {
            $violations[] = "{$user} viu {$pair}, que não é o seu âmbito ({$row['scope']})";
        }
    }
    if ($row['frames'] > 0) {
        $producing[] = $row['scope'];
    }

    printf(
        "%-16s %-16s %-8d %-9d %s\n",
        $user,
        $row['scope'],
        $row['frames'],
        count($row['devices']),
        $pairs === [] ? '(nada -- inquilino sem tráfego na janela)' : implode(' ', $pairs),
    );
}

echo "\n";
if ($violations !== []) {
    echo "FALHOU:\n";
    foreach ($violations as $v) {
        echo "  - {$v}\n";
    }
    exit(1);
}

$count = count($producing);
if ($count < 2) {
    echo "INCONCLUSIVO: só {$count} inquilino teve tráfego na janela.\n";
    echo "Nenhum stream viu nada que não fosse seu, mas com um produtor só isso não prova\n";
    echo "isolamento -- prova apenas que os outros estavam calados. Repetir com uma janela\n";
    echo "maior, ou com inquilinos que se saiba estarem activos.\n";
    exit(1);
}

echo "PASSOU: {$count} inquilinos produziram na mesma janela, e nenhum stream recebeu um\n";
echo "único frame de um par empresa+licença que não fosse o seu.\n";
echo "Produtores concorrentes: " . implode(', ', $producing) . "\n";
