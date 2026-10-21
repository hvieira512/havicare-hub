<?php

declare(strict_types=1);

namespace Hub\Api\Auth;

use Predis\ClientInterface;

/**
 * Os tetos de tentativas de autenticação: por endereço, por utilizador e global. O
 * `password_verify` custa ~146 ms síncronos no event loop, e o global trava a rotação de IP.
 *
 * ponytail: janela fixa, que numa fronteira deixa passar o dobro; apertar com janela deslizante.
 */
final class LoginThrottle
{
    private const PREFIX = 'hub:login-throttle';

    public function __construct(
        private ClientInterface $redis,
        private int $maxPerAddress = 20,
        private int $windowPerAddressSeconds = 60,
        private int $maxPerUsername = 10,
        private int $windowPerUsernameSeconds = 300,
        private int $maxGlobal = 15,
        private int $windowGlobalSeconds = 10,
    ) {
    }

    /**
     * Regista uma tentativa e diz se ela pode seguir para a verificação da password. Conta-se
     * antes de verificar, e conta-se toda a tentativa, acerte ou falhe.
     */
    public function allows(string $address, string $username): bool
    {
        // Os três contam sempre, mesmo quando o primeiro já recusou: senão um endereço
        // bloqueado deixa de alimentar o teto global, e a rotação de endereços passa.
        $withinAddress = $this->count('ip', $address, $this->windowPerAddressSeconds) <= $this->maxPerAddress;
        $withinUsername = $this->count('user', strtolower($username), $this->windowPerUsernameSeconds)
            <= $this->maxPerUsername;
        $withinGlobal = $this->count('all', '', $this->windowGlobalSeconds) <= $this->maxGlobal;

        return $withinAddress && $withinUsername && $withinGlobal;
    }

    private function count(string $bucket, string $subject, int $windowSeconds): int
    {
        $window = intdiv(time(), max(1, $windowSeconds));
        $key = self::PREFIX . ':' . $bucket . ':' . $subject . ':' . $window;

        $used = (int)$this->redis->incr($key);
        if ($used === 1) {
            // Só na primeira, para a janela fechar no prazo e não andar com cada tentativa.
            $this->redis->expire($key, $windowSeconds * 2);
        }

        return $used;
    }
}
