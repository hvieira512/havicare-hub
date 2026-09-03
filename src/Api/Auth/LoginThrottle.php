<?php

declare(strict_types=1);

namespace Hub\Api\Auth;

use Predis\ClientInterface;

/**
 * Os tetos de tentativas de autenticação.
 *
 * O `password_verify` está a custo 12 -- 145,6 ms medidos no servidor --, é síncrono, e corre
 * no mesmo event loop que serve a ingestão TCP dos relógios e a API. Cerca de sete tentativas
 * por segundo bastam para parar o processo inteiro, e o login é a **única** rota pública que
 * faz esse trabalho: tem de o ser, porque não se pode apresentar um token antes de o ter.
 *
 * O custo é pago quando a tentativa **falha**, e não só quando acerta. Quem ataca não precisa
 * de adivinhar a password -- precisa apenas de obrigar o hub a verificar.
 *
 * São três tetos porque cada um fecha uma porta que os outros deixam aberta:
 *
 * - **Por endereço** trava o atacante único, que é o caso comum.
 * - **Por utilizador** trava quem distribui as tentativas contra uma conta só, vindo de muitos
 *   endereços -- a forma que as credenciais recicladas de outra fuga costumam ter.
 * - **Global** é o que fixa o tempo de loop gasto em bcrypt, independentemente de quantos
 *   endereços o atacante tenha. Sem ele os outros dois são derrotados por rotação de IP.
 *
 * A janela vive na chave, e por isso o `expire` serve apenas para o Redis não guardar
 * contadores de janelas que já passaram.
 *
 * ponytail: janela fixa, e portanto quem calhar numa fronteira consegue o dobro do orçamento
 * num intervalo curto -- com o teto global a 10 por 10 s, até 20 tentativas de uma vez, ou
 * ~3,5 s de loop. É o comportamento conhecido das janelas fixas e aqui é aceitável, porque o
 * pior caso é um atraso e não uma recusa. Se um dia interessar apertá-lo, o passo seguinte é
 * uma janela deslizante (contadores por sub-intervalo) e não um teto mais baixo, que castigava
 * o uso legítimo.
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
     * loop exactamente o mesmo que uma que falhe, e é o tempo de loop que estes tetos
     * protegem. É também por isto que uma aplicação bem feita guarda o par de tokens e renova
     * em vez de voltar a autenticar -- o caminho do `refresh_token` não passa por aqui.
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
