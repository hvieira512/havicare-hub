<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * `reset_device` é sempre a reposição de fábrica e `restart_device` sempre o reinício. A frase
 * de confirmação vive na definição porque o efeito exato é do aparelho de cada protocolo.
 */
final class DestructiveConfirmationTest extends TestCase
{
    /**
     * As capacidades cujo efeito não se desfaz a partir da dashboard: o aparelho deixa de
     * comunicar, perde o que estava a fazer, ou liga a um número sem avisar quem o traz.
     *
     * @var list<string>
     */
    private const IRREVERSIBLE = [
        'reset_device',
        'restart_device',
        'power_off',
        'monitor_number',
    ];

    public function testEveryIrreversibleActionDeclaresWhatItDoes(): void
    {
        $missing = [];

        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)($entry['key'] ?? '');
                $capability = CapabilityCatalog::mapConfigurationKey($key);
                if (!in_array($capability, self::IRREVERSIBLE, true)) {
                    continue;
                }

                if (trim((string)($entry['confirm'] ?? '')) === '') {
                    $missing[] = $protocol . '/' . $key;
                    continue;
                }

                self::assertSame(
                    'destructive',
                    $entry['risk'] ?? '',
                    $protocol . '/' . $key . ' declara uma confirmação e tem de sair como destrutiva',
                );
            }
        }

        self::assertSame([], $missing, 'estas acções não dizem o que fazem ao aparelho');
    }

    public function testTheFactoryResetDoesNotPromiseARestart(): void
    {
        $reset = $this->entry('wonlex-json', 'factoryResetCommand');

        self::assertMatchesRegularExpression(
            '/f[áa]brica/iu',
            (string)($reset['confirm'] ?? ''),
            'a confirmação da Wonlex tem de dizer que repõe o aparelho de fábrica',
        );
        self::assertDoesNotMatchRegularExpression(
            '/arranca|reinici/iu',
            (string)($reset['confirm'] ?? ''),
            'a reposição de fábrica não se confirma com o texto de um reinício',
        );
    }

    /** O `RESET` do 4P Touch reinicia (§15 da especificação); a reposição de fábrica é o `FACTORY`. */
    public function testTheFourPTouchResetIsARestart(): void
    {
        $fourPTouch = $this->entry('four-p-touch', 'restartCommand');

        self::assertSame('restart_device', CapabilityCatalog::mapConfigurationKey('restartCommand'));
        self::assertMatchesRegularExpression(
            '/arranca|reinici/iu',
            (string)($fourPTouch['confirm'] ?? ''),
            'o RESET do 4P Touch é um reinício, e é isso que a caixa tem de dizer',
        );
        self::assertNotContains(
            'reset_device',
            CapabilityCatalog::keysForProtocol('four-p-touch'),
            'o 4P Touch não declara a reposição de fábrica',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $protocol, string $key): array
    {
        $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $key);
        self::assertNotNull($entry, $protocol . ' devia declarar a configuração ' . $key);

        return $entry;
    }
}
