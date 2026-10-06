<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Log\Logger;
use React\EventLoop\LoopInterface;

/**
 * Diz se o arranque anterior terminou de repente: um marcador escrito ao arrancar e apagado no
 * `SIGTERM` que, encontrado, denuncia uma queda que de outro modo só ficava no `journalctl`.
 */
final class CrashWatch
{
    public function __construct(private string $markerPath)
    {
    }

    /**
     * Toma posse do arranque, relata a queda anterior no sino da dashboard e liga o desligar limpo
     * ao loop. Repetições incrementam o contador e voltam a pô-la por ler.
     */
    public static function attach(LoopInterface $loop, HubServices $services, string $markerPath): self
    {
        $watch = new self($markerPath);

        $uncleanShutdown = $watch->claimBoot();
        if ($uncleanShutdown !== null) {
            Logger::channel('hub')->error("Previous run ended abruptly: {$uncleanShutdown}");
            $services->dataAccess->dashboardNotifications->record(
                'hub_unclean_restart',
                'hub',
                '',
                '',
                (string)gethostname(),
                $uncleanShutdown,
            );
        }

        foreach ([SIGTERM, SIGINT] as $signal) {
            $loop->addSignal($signal, static function () use ($watch, $loop): void {
                $watch->markCleanShutdown();
                $loop->stop();
            });
        }

        return $watch;
    }

    /**
     * Toma posse deste arranque e descreve o anterior, se tiver morrido a meio; lê antes de
     * escrever. Devolve `null` também no primeiro arranque, indistinguível de um normal.
     */
    public function claimBoot(): ?string
    {
        $previous = is_file($this->markerPath)
            ? trim((string)file_get_contents($this->markerPath))
            : '';

        $directory = dirname($this->markerPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($this->markerPath, sprintf('%d %s', getmypid(), gmdate('Y-m-d\TH:i:s\Z')));

        if ($previous === '') {
            return null;
        }

        [$pid, $startedAt] = array_pad(explode(' ', $previous, 2), 2, '');

        return $pid === '' || $startedAt === ''
            ? 'o processo anterior terminou sem se desligar em condições'
            : sprintf('o processo %s, arrancado em %s, terminou sem se desligar em condições', $pid, $startedAt);
    }

    /** Chamado quando o processo se desliga a pedido, e é isto que distingue as duas coisas. */
    public function markCleanShutdown(): void
    {
        if (is_file($this->markerPath)) {
            unlink($this->markerPath);
        }
    }
}
