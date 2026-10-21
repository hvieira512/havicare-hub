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

        $previousRun = $watch->claimBoot();
        if ($previousRun !== null) {
            Logger::channel('hub')->error('Previous run ended abruptly', $previousRun);
            $services->dataAccess->dashboardNotifications->record(
                'hub_unclean_restart',
                'hub',
                '',
                '',
                (string)gethostname(),
                (string)json_encode($previousRun),
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
     * Toma posse deste arranque e devolve o anterior, se tiver morrido a meio; lê antes de
     * escrever. Devolve `null` também no primeiro arranque, indistinguível de um normal.
     *
     * @return array{pid: ?int, startedAt: ?string}|null
     */
    public function claimBoot(): ?array
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

        return $pid === '' || $startedAt === '' || !ctype_digit($pid)
            ? ['pid' => null, 'startedAt' => null]
            : ['pid' => (int)$pid, 'startedAt' => $startedAt];
    }

    /** Chamado quando o processo se desliga a pedido, e é isto que distingue as duas coisas. */
    public function markCleanShutdown(): void
    {
        if (is_file($this->markerPath)) {
            unlink($this->markerPath);
        }
    }
}
