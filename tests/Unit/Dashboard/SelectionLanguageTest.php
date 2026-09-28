<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * As cinco caixas que se escolhem com o rato dizem todas o mesmo em repouso, por cima, no
 * foco e escolhidas. Isso vive numa regra partilhada no `shell.css`; o ficheiro da área de
 * cada uma leva só a geometria.
 *
 * Falharam-no uma vez: duas passavam a borda cheia da marca por cima e as outras três a
 * subtil, com tempos diferentes, e só duas desenhavam anel de foco.
 */
final class SelectionLanguageTest extends TestCase
{
    private const BOXES = [
        '.device-type-tile',
        '.device-card',
        '.capability-section-chip',
        '.wizard-card',
        '.gateway-card',
    ];

    /** A marca que identifica cada uma das quatro regras partilhadas. */
    private const SHARED_RULES = [
        'em repouso' => 'cursor: pointer',
        'por cima' => 'var(--bs-tertiary-bg)',
        'no foco' => 'outline: 2px solid',
        'escolhida' => 'var(--bs-primary-bg-subtle)',
    ];

    public function testEverySelectableBoxSharesEveryState(): void
    {
        $rules = $this->rulesOf($this->read('assets/css/shell.css'));

        foreach (self::SHARED_RULES as $state => $mark) {
            $rule = '';
            foreach ($rules as $candidate) {
                if (str_contains($candidate, '.device-type-tile') && str_contains($candidate, $mark)) {
                    $rule = $candidate;
                    break;
                }
            }

            $this->assertNotSame('', $rule, "Não se encontrou no shell.css a regra de «{$state}».");

            foreach (self::BOXES as $box) {
                $this->assertStringContainsString($box, $rule, sprintf(
                    'A caixa %s ficou de fora da regra de «%s».',
                    $box,
                    $state,
                ));
            }
        }
    }

    /** As áreas ficam com a geometria: quem lá declarar um estado volta a partir a língua. */
    public function testTheAreaSheetsDoNotRedeclareTheStates(): void
    {
        foreach (['assets/css/device.css', 'main.css'] as $sheet) {
            $source = $this->read($sheet);

            foreach (self::BOXES as $box) {
                foreach ([$box . ':hover', $box . ':focus-visible'] as $state) {
                    $this->assertStringNotContainsString($state, $source, "{$sheet}: {$state}");
                }

                // O `.selected` sozinho, e não o das regras que descem para dentro da caixa.
                $this->assertDoesNotMatchRegularExpression(
                    '/' . preg_quote($box, '/') . '\.selected\s*[,{]/',
                    $source,
                    "{$sheet}: {$box}.selected",
                );
            }
        }
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../src/Dashboard/' . $relative);
    }

    /** @return list<string> cada regra do ficheiro, selectores e corpo juntos */
    private function rulesOf(string $source): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $source, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $rule): string => $rule[0], $matches);
    }
}
