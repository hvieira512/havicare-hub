<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

/**
 * O ciclo de vida de uma medição a assentar, entre a primeira trama e a confirmação. Não
 * publica: guarda e diz quando sai, e quem emite é a bridge.
 */
final class SettlingReadings
{
    /**
     * Quanto tempo uma leitura espera pela confirmação antes de sair sozinha: mais do que a
     * medição mais lenta, a composição corporal, de dois minutos.
     */
    private const HOLD_SECONDS = 180;

    /**
     * A leitura mais recente de cada pedido em curso, por aparelho: o resultado é o valor com
     * que a medição assentou, o mesmo que a app mostra.
     *
     * @var array<string, array<string, array{at: float, telemetry: array<string, mixed>, context: BraceletContext}>>
     */
    private array $settling = [];

    /**
     * Pedidos já encerrados, à espera da confirmação que ainda vem a caminho, para não
     * falharem duas vezes.
     *
     * @var array<string, array<string, float>>
     */
    private array $closed = [];

    /** @param \Closure(): float $clock */
    public function __construct(private readonly \Closure $clock)
    {
    }

    /**
     * Guarda a leitura com que a medição vai assentando, substituindo a anterior.
     *
     * @param array<string, mixed> $telemetry
     */
    public function hold(BraceletContext $context, string $operation, array $telemetry): void
    {
        $this->settling[$context->deviceKey][$operation] = [
            'at' => ($this->clock)(),
            'telemetry' => $telemetry,
            'context' => $context,
        ];
    }

    /** Encerra o pedido: larga o que estivesse a assentar e fica à espera da confirmação. */
    public function close(string $deviceKey, string $operation): void
    {
        $this->closed[$deviceKey][$operation] = ($this->clock)();
        unset($this->settling[$deviceKey][$operation]);
    }

    /** Se esta confirmação é de um pedido já encerrado; consome a marca e a leitura pendente. */
    public function discardConfirmation(string $deviceKey, string $operation): bool
    {
        if (!isset($this->closed[$deviceKey][$operation])) {
            return false;
        }

        unset($this->closed[$deviceKey][$operation], $this->settling[$deviceKey][$operation]);

        return true;
    }

    /**
     * O valor com que o pedido assentou, retirando-o: cada pedido dá o seu.
     *
     * @return array<string, mixed>|null a telemetria pronta a publicar
     */
    public function takeSettled(string $deviceKey, string $operation): ?array
    {
        $settled = $this->settling[$deviceKey][$operation] ?? null;
        unset($this->settling[$deviceKey][$operation]);
        if ($settled === null) {
            return null;
        }

        return $settled['telemetry'];
    }

    /**
     * As leituras cuja confirmação passou do prazo, uma de cada vez: uma publicação que
     * rebente deixa as restantes onde estão.
     *
     * @return \Generator<int, array{context: BraceletContext, telemetry: array<string, mixed>}>
     */
    public function release(): \Generator
    {
        $now = ($this->clock)();

        // A marca de encerrado também expira, para o pedido seguinte poder falhar por si.
        foreach ($this->closed as $deviceKey => $operations) {
            foreach ($operations as $operation => $at) {
                if ($now - $at >= self::HOLD_SECONDS) {
                    unset($this->closed[$deviceKey][$operation]);
                }
            }
        }

        foreach ($this->settling as $deviceKey => $operations) {
            foreach ($operations as $operation => $settled) {
                if ($now - $settled['at'] < self::HOLD_SECONDS) {
                    continue;
                }
                unset($this->settling[$deviceKey][$operation]);
                yield [
                    'context' => $settled['context'],
                    'telemetry' => $settled['telemetry'],
                ];
            }
        }
    }
}
