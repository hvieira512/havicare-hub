<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Uma acção que o utilizador não desfaz tem de dizer, na definição, o que faz ao aparelho.
 *
 * A mesma capacidade significa coisas diferentes conforme o protocolo: o `reset_device` da
 * Wonlex é uma reposição de fábrica, o do 4P Touch é um reinício. Quem sabe o que o comando
 * faz é quem escreveu o adaptador, e é por isso que a frase vive na definição.
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
        $reset = $this->entry('wonlex-json', 'resetCommand');

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

    public function testTheSameCapabilityIsConfirmedByWhatEachProtocolDoes(): void
    {
        $fourPTouch = $this->entry('four-p-touch', 'resetCommand');

        self::assertMatchesRegularExpression(
            '/arranca|reinici/iu',
            (string)($fourPTouch['confirm'] ?? ''),
            'no 4P Touch a mesma capacidade é um reinício, e é isso que a caixa tem de dizer',
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
