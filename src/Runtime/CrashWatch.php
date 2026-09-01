<?php

declare(strict_types=1);

namespace Hub\Runtime;

/**
 * Diz se o arranque anterior terminou de repente. O `Restart=always` levanta o processo em
 * milissegundos, e sem isto uma queda só deixava rasto no `journalctl`, onde ninguém olha.
 *
 * Escreve-se um ficheiro ao arrancar e apaga-se ao desligar em condições: encontrá-lo ao
 * arrancar quer dizer que o anterior não passou pelo `SIGTERM`. Não adivinha a causa -- só
 * garante que alguém fica a saber.
 */
final class CrashWatch
{
    public function __construct(private string $markerPath)
    {
    }

    /**
     * Toma posse deste arranque e descreve o anterior, se ele tiver morrido a meio.
     *
     * A leitura vem antes da escrita, porque é o marcador do arranque anterior que carrega a
     * resposta. Devolve `null` quando o anterior se despediu -- ou quando é o primeiro de
     * todos, que é indistinguível e deve ser tratado como normal.
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
