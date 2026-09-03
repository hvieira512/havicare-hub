<?php

declare(strict_types=1);

namespace Hub\Runtime;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * O sinal de vida que o systemd espera, enviado de dentro do event loop.
 *
 * O `Restart=always` da unit reage ao processo **terminar**, e o modo de falha que mais
 * interessa não termina nada. Quando o hub esgotou os descritores que o `select()` conseguia
 * vigiar, ficou vivo e deixou de servir: a API sem resposta, os descritores nunca libertados, e
 * o systemd a reportar `active (running)` porque não havia nada de errado que ele soubesse ver.
 * Foi preciso reiniciar à mão.
 *
 * O ping fecha essa lacuna precisamente porque sai de um temporizador do loop: **só é enviado
 * se o loop estiver a girar.** É prova de vivacidade, e não de existência -- que é a diferença
 * entre o que o systemd sabia e o que precisava de saber.
 *
 * Fora do systemd é inerte. Sem `NOTIFY_SOCKET` ou sem o watchdog armado, o
 * `fromEnvironment()` devolve `null` e não se registam temporizadores.
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
     * Registra o temporizador que mantém o serviço declarado vivo.
     *
     * O temporizador devolvido serve para o cancelar; em produção nada o cancela, porque o
     * processo só deixa de precisar dele quando termina.
     */
    public function attach(LoopInterface $loop): ?TimerInterface
    {
        return $loop->addPeriodicTimer($this->pingIntervalSeconds, function (): void {
            $this->ping();
        });
    }

    /**
     * Um datagrama para o socket do systemd, e nada mais.
     *
     * Falhar aqui não pode derrubar o hub: se o socket desapareceu, o pior que acontece é o
     * systemd deixar de ver pings e reiniciar o serviço -- que é exactamente o comportamento
     * que se quer. Uma excepção a subir daqui matava o processo por causa do mecanismo que
     * existe para o proteger.
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
