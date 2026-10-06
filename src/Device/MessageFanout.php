<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * Entrega a quem tem um stream aberto as mensagens do seu inquilino, pela chave
 * `empresa/licença/canal`: a 1001 do hitcare e a 1001 do havicare são clientes diferentes.
 */
class MessageFanout
{
    /** @var array<string, array<int, callable(string, string): void>> */
    private array $listeners = [];

    private int $nextId = 1;

    /**
     * @param callable(string, string): void $listener recebe o tópico e o payload já serializado
     * @return callable(): void unsubscribe
     */
    public function subscribe(string $scope, callable $listener): callable
    {
        $id = $this->nextId++;
        $this->listeners[$scope][$id] = $listener;

        return function () use ($scope, $id): void {
            unset($this->listeners[$scope][$id]);
            if (($this->listeners[$scope] ?? []) === []) {
                unset($this->listeners[$scope]);
            }
        };
    }

    /**
     * O payload chega já serializado e é escrito a todos os ouvintes sem segunda codificação:
     * é a mesma string que vai para o fio.
     */
    public function dispatch(string $scope, string $topic, string $json): void
    {
        foreach ($this->listeners[$scope] ?? [] as $listener) {
            $listener($topic, $json);
        }
    }

    /**
     * Quem publica pergunta isto antes de compor a chave, e por isso o custo em repouso é uma
     * comparação de array vazio -- não uma concatenação por mensagem.
     */
    public function hasListeners(): bool
    {
        return $this->listeners !== [];
    }

    /**
     * A chave que produtor e consumidor compõem da mesma maneira. A empresa vai em minúsculas
     * porque o `canAccessTenant` a compara sem distinguir caixa.
     */
    public static function scope(string $company, int $licenseId, string $channel): string
    {
        return strtolower(trim($company, '/')) . '/' . $licenseId . '/' . $channel;
    }

    public function listenerCount(): int
    {
        return array_sum(array_map('count', $this->listeners));
    }
}
