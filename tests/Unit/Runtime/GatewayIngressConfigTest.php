<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Config;
use PHPUnit\Framework\TestCase;

/**
 * A secção `gateway` configura a ingestão de gateways, de que as pulseiras Veepoo também
 * dependem, e por isso não leva o nome de um fornecedor.
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
        // As duas máquinas têm `MOKO_GATEWAY_*` no `.env`, e deixar de as ler desligaria os gateways.
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
        // As pulseiras Veepoo são retransmitidas pelo gateway, e por isso a variável leva o nome dele.
        $this->env('MOKO_GATEWAY_ENABLED', 'false');

        self::assertFalse(Config::load()->all()['gateway']['enabled']);
    }
}
