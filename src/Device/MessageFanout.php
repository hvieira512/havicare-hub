<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * Entrega a quem tem um stream aberto as mensagens do seu próprio inquilino.
 *
 * A chave é o âmbito -- `empresa/licença/canal` --, e não o dispositivo. Uma mensagem só
 * chega a quem está registado sob a sua própria chave, e por isso não existe caminho onde
 * uma mensagem de outro inquilino seja considerada e depois recusada: nunca é procurada.
 * A licença sozinha não serve de chave, porque a 1001 do hitcare e a 1001 do havicare são
 * clientes diferentes.
 *
 * Ao contrário do `DeviceUpdateNotifier`, que diz apenas *qual* o dispositivo mudou, aqui a
 * mensagem viaja com o ouvinte: um espelho não tem estado autoritativo para reler, e uma
 * notificação perdida seria a mensagem perdida.
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
     * A chave que o produtor e o consumidor têm de compor da mesma maneira, e é por isso que
     * vive aqui em vez de nos dois lados.
     *
     * A empresa vem em minúsculas porque o `canAccessTenant` a compara sem distinguir caixa, e
     * as duas pontas têm de chegar à mesma string. Os outros dois segmentos são um inteiro e
     * um de quatro literais, pelo que nenhuma empresa consegue produzir a chave de outra.
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
