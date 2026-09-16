<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

/**
 * O ciclo de vida de uma medição a assentar, entre a primeira trama e a confirmação.
 *
 * Não publica nada: guarda o que há para publicar e diz quando é que sai. Quem emite é a
 * bridge, que é quem tem o MQTT e a dashboard.
 */
final class SettlingReadings
{
    /**
     * Quanto tempo uma leitura espera pela confirmação antes de sair sozinha.
     *
     * Mais do que qualquer medição desta pulseira: a mais lenta, a composição corporal, leva
     * dois minutos. O prazo existe para uma confirmação perdida não levar com ela um valor
     * que o aparelho chegou a medir.
     */
    private const HOLD_SECONDS = 180;

    /**
     * A leitura mais recente de cada pedido em curso, por aparelho.
     *
     * Enquanto mede, o firmware manda uma trama por segundo e o valor anda: um só pedido de
     * frequência cardíaca, numa pulseira ao pulso, deu dezanove leituras entre 79 e 97. Isso
     * é a medição a assentar, e o resultado é o valor com que ela assentou -- o mesmo que a
     * app do fabricante mostra. Publicar o caminho todo enchia o histórico do aparelho com o
     * decorrer de uma medição em vez do que ela deu.
     *
     * @var array<string, array<string, array{at: float, telemetry: array<string, mixed>, licenseId: int, company: string}>>
     */
    private array $settling = [];

    /**
     * Pedidos já encerrados, à espera da confirmação que ainda vem a caminho.
     *
     * A pulseira diz `notWear` a meio da medição e o pedido morre aí. A confirmação chega a
     * seguir -- o gateway executou o comando -- e encontrava-o sem leitura nenhuma à espera,
     * dando-o por falhado outra vez e agora por não ter dado valor. Eram dois acontecimentos
     * para o mesmo toque no botão, e o segundo apontava para o sensor quando o problema era
     * o pulso.
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
    public function hold(
        string $deviceKey,
        string $operation,
        array $telemetry,
        int $licenseId,
        string $company,
    ): void {
        $this->settling[$deviceKey][$operation] = [
            'at' => ($this->clock)(),
            'telemetry' => $telemetry,
            'licenseId' => $licenseId,
            'company' => $company,
        ];
    }

    /** Encerra o pedido: larga o que estivesse a assentar e fica à espera da confirmação. */
    public function close(string $deviceKey, string $operation): void
    {
        $this->closed[$deviceKey][$operation] = ($this->clock)();
        unset($this->settling[$deviceKey][$operation]);
    }

    /**
     * Se esta confirmação é de um pedido já encerrado, e por isso não é para ninguém.
     *
     * Consome a marca e a leitura pendente: o pedido morre uma vez só, e um valor que tenha
     * chegado depois dele é do sensor a medir o que já não interessa.
     */
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
     * As leituras cuja confirmação nunca chegou e já passaram do prazo.
     *
     * O valor foi medido; se a caixa morrer entre a última trama e a confirmação, ele tem de
     * sair na mesma.
     *
     * Larga uma de cada vez, e não todas de uma assentada: quem as recebe publica-as, e uma
     * publicação que rebente deixa as restantes onde estão para saírem no tique seguinte.
     *
     * @return \Generator<int, array{deviceKey: string, telemetry: array<string, mixed>, licenseId: int, company: string}>
     */
    public function release(): \Generator
    {
        $now = ($this->clock)();

        // Uma confirmação que nunca chega não pode deixar um pedido marcado como morto para
        // sempre: o seguinte tem de poder falhar por si.
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
                    'deviceKey' => $deviceKey,
                    'telemetry' => $settled['telemetry'],
                    'licenseId' => $settled['licenseId'],
                    'company' => $settled['company'],
                ];
            }
        }
    }
}
