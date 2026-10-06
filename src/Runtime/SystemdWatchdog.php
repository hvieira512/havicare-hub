<?php

declare(strict_types=1);

namespace Hub\Runtime;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * O sinal de vida que o systemd espera, enviado de um temporizador do event loop: só sai com o
 * loop a girar, e apanha um hub vivo que deixou de servir. Fora do systemd é inerte.
 */
final class SystemdWatchdog
{
    private function __construct(
        private string $socketPath,
        private float $pingIntervalSeconds,
    ) {
    }

    /**
     * @param array<string, string>|null $environment o ambiente a ler; `null` usa o do processo
     */
    public static function fromEnvironment(?array $environment = null): ?self
    {
        $environment ??= self::processEnvironment();

        $socket = trim((string)($environment['NOTIFY_SOCKET'] ?? ''));
        // O systemd conta em microssegundos, e a chave só aparece quando o `WatchdogSec=` está
        // configurado. A zero, ninguém está a vigiar.
        $watchdogUsec = (int)($environment['WATCHDOG_USEC'] ?? 0);

        if ($socket === '' || $watchdogUsec <= 0) {
            return null;
        }

        // Metade do intervalo pedido, que é a convenção do systemd: deixa margem para um ping
        // se perder sem o serviço ser declarado morto.
        return new self($socket, $watchdogUsec / 2_000_000);
    }

    public function pingIntervalSeconds(): float
    {
        return $this->pingIntervalSeconds;
    }

    /**
     * Regista o temporizador que mantém o serviço declarado vivo; devolve-o para se poder
     * cancelar.
     */
    public function attach(LoopInterface $loop): ?TimerInterface
    {
        return $loop->addPeriodicTimer($this->pingIntervalSeconds, function (): void {
            $this->ping();
        });
    }

    /**
     * Um datagrama para o socket do systemd, e nada mais. Falhar não derruba o hub: sem pings, o
     * systemd reinicia o serviço, que é o que se quer.
     */
    public function ping(): void
    {
        $socket = @socket_create(AF_UNIX, SOCK_DGRAM, 0);
        if ($socket === false) {
            return;
        }

        // O systemd usa o espaço de nomes abstracto quando o caminho começa por `@`, e aí o
        // primeiro byte tem de ser nulo.
        $path = $this->socketPath;
        if (str_starts_with($path, '@')) {
            $path = "\0" . substr($path, 1);
        }

        $message = 'WATCHDOG=1';
        @socket_sendto($socket, $message, strlen($message), 0, $path, 0);
        socket_close($socket);
    }

    /** @return array<string, string> */
    private static function processEnvironment(): array
    {
        $environment = [];
        foreach (['NOTIFY_SOCKET', 'WATCHDOG_USEC'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = (string)$value;
            }
        }

        return $environment;
    }
}
