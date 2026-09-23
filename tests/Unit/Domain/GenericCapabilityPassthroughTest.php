<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\Capability\GenericCapability;
use PHPUnit\Framework\TestCase;

/**
 * A capacidade genérica serve todos os protocolos que anuncia, e ela anuncia todos.
 *
 * É o contrato de quem não tem contrato próprio, e por isso não declara restrição nenhuma.
 * Quem traduz nomes continua a traduzir; para os outros, o nome nativo é a própria chave
 * genérica e passar o valor tal e qual é a resposta certa, não uma omissão.
 */
final class GenericCapabilityPassthroughTest extends TestCase
{
    /**
     * Instanciada directamente e não pelo registo: o que se prende aqui é o comportamento
     * desta classe, e passar pelo registo faria o teste depender de que chaves é que hoje têm
     * contrato próprio.
     */
    private function contract(string $genericKey): GenericCapability
    {
        return new GenericCapability($genericKey, new FourPTouchGenericHandler());
    }

    public function testAProtocolThatTranslatesKeepsTranslating(): void
    {
        // O 4P Touch traduz `fall_detection` para o nome de fio `fallDownAlert`.
        self::assertSame(
            ['fallDownAlert' => ['enabled' => true]],
            $this->contract('fall_detection')->toNative('four-p-touch', ['enabled' => true]),
        );
    }

    public function testAProtocolWithoutTranslationPassesTheValueThrough(): void
    {
        // O `moko-w6` não tem tradução declarada. Antes rebentava; agora o nome nativo é a
        // própria chave genérica, que é o que o destinatário da ordem espera receber.
        self::assertSame(
            ['heart_rate_continuous' => ['enabled' => true]],
            $this->contract('heart_rate_continuous')->toNative('moko-w6', ['enabled' => true]),
        );
    }

    public function testEveryAdvertisedProtocolIsServed(): void
    {
        $contract = $this->contract('child_lock');
        $recusados = [];

        foreach ($contract->supportedProtocols() as $protocol) {
            try {
                $contract->toNative($protocol, ['enabled' => true]);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Unsupported protocol')) {
                    $recusados[] = "{$protocol}: {$e->getMessage()}";
                }
            }
        }

        self::assertSame([], $recusados);
    }

    /** O valor continua a ter de ser um objecto: passar à frente não é deixar passar tudo. */
    public function testTheValueIsStillValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->contract('child_lock')->toNative('moko-w6', 'isto não é um objecto');
    }
}
