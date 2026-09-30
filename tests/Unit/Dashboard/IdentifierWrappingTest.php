<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Um identificador não parte a meio. O `text-break` do Bootstrap é
 * `word-break: break-word !important`, que serve texto corrido e não um IMEI de quinze
 * dígitos nem um nome de licença: com ele, o `351266770073676` sai em duas linhas e a
 * `gerpi1.casabrancaresidencial` fica `…casabrancaresid` / `encial`.
 *
 * Quem não cabe corta-se com reticências e leva o valor inteiro no `title`.
 */
final class IdentifierWrappingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../src/Dashboard/';

    /**
     * Os sítios onde um identificador é desenhado, e o trecho que o marca em cada um.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function identifierMarkup(): array
    {
        return [
            'IMEI no resumo do dispositivo' => [
                'components/device-column.php',
                'id="selectedDeviceTitle"',
            ],
            'licença e SIM no resumo do dispositivo' => [
                'dashboard/devices/detail.js',
                '<dd class=',
            ],
            'IMEI de uma notificação' => [
                'dashboard/notifications.js',
                'font-monospace small',
            ],
            'identidade de um aparelho bloqueado' => [
                'dashboard/settings/denylist.js',
                'font-monospace',
            ],
            'chave de um par dispositivo-gateway' => [
                'dashboard/devices/gateway-signal.js',
                'font-monospace',
            ],
        ];
    }

    /** @dataProvider identifierMarkup */
    public function testIdentifiersDoNotBreakMidToken(string $file, string $needle): void
    {
        $line = $this->lineContaining($file, $needle);

        $this->assertStringNotContainsString('text-break', $line, sprintf(
            "%s ainda tem `text-break`, que parte o identificador a meio.\nLinha: %s",
            $file,
            trim($line),
        ));
    }

    private function lineContaining(string $file, string $needle): string
    {
        $path = self::ROOT . $file;
        $source = (string) file_get_contents($path);

        foreach (explode("\n", $source) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        self::fail(sprintf('Não se encontrou `%s` em %s -- o teste ficou a apontar para o sítio errado.', $needle, $file));
    }
}
