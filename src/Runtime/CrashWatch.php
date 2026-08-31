<?php

declare(strict_types=1);

namespace Hub\Runtime;

/**
 * Diz se o arranque anterior terminou de repente.
 *
 * O hub corre com `Restart=always`, e isso é o que se quer -- mas é também o que esconde o
 * problema: durante catorze dias o processo morreu doze vezes por falta de memória e ninguém
 * deu por isso, porque o systemd voltava a levantá-lo em milissegundos e a única prova ficava
 * no `journalctl`, onde ninguém estava a olhar.
 *
 * O mecanismo é o mais simples que funciona: escreve-se um ficheiro ao arrancar e apaga-se ao
 * desligar em condições. Se ao arrancar o ficheiro ainda lá estiver, o arranque anterior não
 * chegou a apagá-lo -- ou seja, não passou pelo `SIGTERM`. Um `make prod-update` desliga pelo
 * systemd, que manda `SIGTERM`, e por isso não conta como queda.
 *
 * Não tenta adivinhar a causa: para isso há o `journalctl`. O que faz é garantir que alguém
 * fica a saber que aconteceu.
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
