<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Config;
use PHPUnit\Framework\TestCase;

/**
 * A secção que configura a ingestão de gateways.
 *
 * Chamava-se `moko` e as definições lá dentro são genéricas — o filtro de tópicos, a janela
 * de deduplicação, o tempo de inatividade. O nome do fornecedor na chave fazia a ingestão das
 * pulseiras Veepoo parecer refém do MOKO, quando o que ela precisa é do gateway.
 *
 * As variáveis de ambiente antigas continuam a valer: estão nos `.env` das duas máquinas, e
 * renomeá-las sem mais era desligar os gateways no arranque seguinte.
 */
final class GatewayIngressConfigTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    protected function tearDown(): void
    {
        foreach ($this->touched as $name) {
            putenv($name);
        }
        $this->touched = [];
    }

    private function env(string $name, string $value): void
    {
        $this->touched[] = $name;
        putenv("{$name}={$value}");
    }

    public function testTheSectionIsNamedForTheGatewayAndNotForOneVendor(): void
    {
        $config = Config::load()->all();

        self::assertArrayHasKey('gateway', $config, 'a ingestão de gateways tem secção própria');
        self::assertArrayNotHasKey('moko', $config, 'e já não se chama pelo fornecedor');
    }

    public function testTheDeployedEnvironmentVariablesKeepWorking(): void
    {
        // As duas máquinas têm `MOKO_GATEWAY_*` no `.env`. Deixar de as ler era desligar os
        // gateways silenciosamente no arranque seguinte.
        $this->env('MOKO_GATEWAY_TOPIC_FILTER', 'antigo/#');
        $this->env('MOKO_GATEWAY_IDLE_TIMEOUT_SECONDS', '999');

        $config = Config::load()->all();

        self::assertSame('antigo/#', $config['gateway']['topic_filter']);
        self::assertSame(999, $config['gateway']['idle_timeout_seconds']);
    }

    public function testTheNewNameWinsWhenBothAreSet(): void
    {
        $this->env('MOKO_GATEWAY_TOPIC_FILTER', 'antigo/#');
        $this->env('GATEWAY_TOPIC_FILTER', 'novo/#');

        self::assertSame('novo/#', Config::load()->all()['gateway']['topic_filter']);
    }

    public function testTheGatewaySwitchIsWhatTheBraceletIngressFollows(): void
    {
        // A dependência é real e fica: as pulseiras Veepoo são retransmitidas pelo gateway, e
        // sem a ingestão dele não há tópico nenhum para ouvir. O que estava errado era o nome
        // dizer «MOKO» quando o que manda é o gateway.
        $this->env('MOKO_GATEWAY_ENABLED', 'false');

        self::assertFalse(Config::load()->all()['gateway']['enabled']);
    }
}
