<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Device\HubMqttBridge;
use Hub\Log\Logger;
use Hub\Runtime\StartupBanner;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;

/**
 * O banner de arranque é o que diz, meses depois, com que configuração se estava a correr.
 *
 * A ingestão Veepoo é o caso que obriga a testá-lo: lê o espaço de tópicos dos gateways,
 * partilhado com o MOKO, e por isso a secção de configuração de onde sai o filtro não tem o
 * nome dela. Enquanto a chave da ingestão servia de índice à configuração, a linha da Veepoo
 * não podia sequer existir -- `$config['veepoo']` não existe.
 */
final class StartupBannerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'banner-log-');
        if ($path === false) {
            throw new \RuntimeException('could not create the temporary log file');
        }
        $this->logFile = $path;
        putenv('LOG_FILE=' . $path);
        Logger::reset();
    }

    protected function tearDown(): void
    {
        putenv('LOG_FILE');
        Logger::reset();
        @unlink($this->logFile);
    }

    public function testTheVeepooIngressReportsTheGatewayTopicFilter(): void
    {
        $output = $this->banner(['moko', 'veepoo']);

        self::assertStringContainsString(
            'Veepoo bracelet ingress topics: havicare-hub/null/0/gw/+/raw',
            $output,
        );
        self::assertStringContainsString(
            'havicare-hub/{company}/{licenseId}/bracelet/{deviceKey}/{raw|status|events|telemetry}',
            $output,
        );
    }

    public function testEachIngressReadsTheFilterOfItsOwnSection(): void
    {
        $output = $this->banner(['ncs', 'qinglanst']);

        self::assertStringContainsString('NCS ingress topics: /voerka/#', $output);
        self::assertStringContainsString('Qinglanst radar ingress: radar/+/+', $output);
    }

    /**
     * Uma ingestão sem descrição não escreve linha nenhuma, e sobretudo não rebenta: o
     * `enabledIngresses` é construído à mão e pode trazer uma chave que a tabela não conhece.
     */
    public function testAnUnknownIngressIsSkippedWithoutFailing(): void
    {
        $output = $this->banner(['inexistente']);

        self::assertStringNotContainsString('inexistente', $output);
        self::assertStringContainsString('=== Havicare Hub ===', $output);
    }

    /** @param list<string> $enabledIngresses */
    private function banner(array $enabledIngresses): string
    {
        StartupBanner::log(
            $this->config(),
            new HubMqttBridge(new FakeMqttSubscriber(), 'havicare-hub'),
            $enabledIngresses,
        );

        return (string)file_get_contents($this->logFile);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'dashboard' => ['host' => '0.0.0.0', 'port' => 8081],
            'tcp_ingress' => ['host' => '0.0.0.0', 'port' => 8080],
            'redis' => ['host' => '127.0.0.1', 'port' => 6379],
            'hub' => ['downlink_queue_ttl_seconds' => 3600],
            'ncs' => ['topic_filter' => '/voerka/#'],
            'moko' => ['topic_filter' => 'havicare-hub/null/0/gw/+/raw'],
            'qinglanst' => ['topic_filter' => 'radar/+/+'],
        ];
    }
}
