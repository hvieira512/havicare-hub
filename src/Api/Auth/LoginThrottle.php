<?php

declare(strict_types=1);

namespace Hub\Api\Auth;

use Predis\ClientInterface;

/**
 * Os tetos de tentativas de autenticação.
 *
 * O `password_verify` está a custo 12 -- 145,6 ms no servidor --, é síncrono, e corre no mesmo
 * event loop que serve a ingestão TCP e a API. O custo é pago quando a tentativa **falha**,
 * e não só quando acerta.
 *
 * São três tetos porque cada um fecha uma porta que os outros deixam aberta:
 *
 * - **Por endereço** trava o atacante único, que é o caso comum.
 * - **Por utilizador** trava quem distribui as tentativas contra uma conta só.
 * - **Global** fixa o tempo de loop gasto em bcrypt, e sem ele os outros dois caem por
 *   rotação de IP.
 *
 * A janela vive na chave, e o `expire` só serve para o Redis não guardar janelas passadas.
 *
 * ponytail: janela fixa, e quem calhar numa fronteira consegue o dobro do orçamento num
 * intervalo curto. Aceitável, porque o pior caso é um atraso e não uma recusa; apertá-lo faz-se
 * com uma janela deslizante (contadores por sub-intervalo), não com um teto mais baixo.
 */
final class LoginThrottle
{
    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:login-throttle',
        private int $maxPerAddress = 20,
        private int $windowPerAddressSeconds = 60,
        private int $maxPerUsername = 10,
        private int $windowPerUsernameSeconds = 300,
        private int $maxGlobal = 15,
        private int $windowGlobalSeconds = 10,
    ) {
        $this->prefix = trim($this->prefix, ':');
    }

    /**
     * Regista uma tentativa e diz se ela pode seguir para a verificação da password.
     *
     * Conta-se **antes** de verificar, e conta-se toda a tentativa: uma que acerte custa ao
     * loop exactamente o mesmo que uma que falhe. O caminho do `refresh_token` não passa aqui.
     */
    public function allows(string $address, string $username): bool
    {
        // Os três contam sempre, mesmo quando o primeiro já recusou: senão um endereço
        // bloqueado deixava de alimentar o teto global, e a rotação de endereços voltava a
        // passar.
        $withinAddress = $this->count('ip', $address, $this->windowPerAddressSeconds) <= $this->maxPerAddress;
        $withinUsername = $this->count('user', strtolower($username), $this->windowPerUsernameSeconds)
            <= $this->maxPerUsername;
        $withinGlobal = $this->count('all', '', $this->windowGlobalSeconds) <= $this->maxGlobal;

        return $withinAddress && $withinUsername && $withinGlobal;
    }

    private function count(string $bucket, string $subject, int $windowSeconds): int
    {
        $window = intdiv(time(), max(1, $windowSeconds));
        $key = $this->prefix . ':' . $bucket . ':' . $subject . ':' . $window;

        $used = (int)$this->redis->incr($key);
        if ($used === 1) {
            // Só na primeira: reescrever o prazo a cada tentativa deixava a janela a andar
            // para a frente e nunca a fechar.
            $this->redis->expire($key, $windowSeconds * 2);
        }

        return $used;
    }
}
