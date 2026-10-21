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

    public function testTheOldMokoNamesAreNotRead(): void
    {
        $this->env('GATEWAY_TOPIC_FILTER', '');
        $this->env('GATEWAY_ENABLED', '');
        $this->env('MOKO_GATEWAY_TOPIC_FILTER', 'antigo/#');
        $this->env('MOKO_GATEWAY_ENABLED', 'false');

        $config = Config::load()->all();

        self::assertSame('havicare-hub/null/0/gw/+/raw', $config['gateway']['topic_filter']);
        self::assertTrue($config['gateway']['enabled']);
    }

    public function testTheIdleTimeoutDefaultsToWhatBothServersRun(): void
    {
        $this->env('GATEWAY_IDLE_TIMEOUT_SECONDS', '');

        self::assertSame(1500, Config::load()->all()['gateway']['idle_timeout_seconds']);
    }

    public function testTheGatewaySwitchIsWhatTheBraceletIngressFollows(): void
    {
        // As pulseiras Veepoo são retransmitidas pelo gateway, e por isso a variável leva o nome dele.
        $this->env('GATEWAY_ENABLED', 'false');

        self::assertFalse(Config::load()->all()['gateway']['enabled']);
    }
}
