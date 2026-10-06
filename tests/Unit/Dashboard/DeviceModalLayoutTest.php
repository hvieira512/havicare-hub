<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * O «Eliminar» não fica na zona do polegar, onde se acerta por engano, e no rodapé só aparece
 * no separador geral.
 */
final class DeviceModalLayoutTest extends TestCase
{
    private static ?string $renderedPage = null;

    private static ?DOMDocument $document = null;

    public function testDeleteLeavesTheFooterForTheGeneralTab(): void
    {
        $delete = $this->element('deleteDeviceBtn');

        $ancestors = [];
        for ($node = $delete->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $ancestors[] = (string)$node->getAttribute('id');
            $ancestors[] = (string)$node->getAttribute('class');
        }

        self::assertContains('deviceGeneralPane', $ancestors);
        foreach ($ancestors as $ancestor) {
            self::assertStringNotContainsString('modal-footer', $ancestor);
        }
    }

    /** O do rodapé nasce escondido: quem o acende é o separador que está aberto. */
    public function testTheFooterDeleteStartsHidden(): void
    {
        $footerDelete = $this->element('deleteDeviceFooterBtn');
        $footer = $footerDelete->parentNode;
        self::assertInstanceOf(DOMElement::class, $footer);

        self::assertStringContainsString('d-none', (string)$footerDelete->getAttribute('class'));
        self::assertStringContainsString('modal-footer', (string)$footer->getAttribute('class'));
    }

    /** A zona perigosa diz-se antes de se carregar nela. */
    public function testDeleteSitsInsideAZoneNamedAsDangerous(): void
    {
        $zone = $this->element('deviceDangerZone');

        self::assertTrue($zone->contains($this->element('deleteDeviceBtn')));
        self::assertStringContainsString('Zona perigosa', (string)$zone->textContent);
    }

    private function element(string $id): DOMElement
    {
        $element = $this->query(sprintf('//*[@id="%s"]', $id));
        self::assertInstanceOf(DOMElement::class, $element, sprintf('A página não desenhou `%s`.', $id));

        return $element;
    }

    /** O mesmo documento em todas as procuras: `contains()` não atravessa duas árvores. */
    private function query(string $expression): ?DOMElement
    {
        if (self::$document === null) {
            self::$document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            self::$document->loadHTML('<?xml encoding="utf-8" ?>' . $this->page());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $found = (new DOMXPath(self::$document))->query($expression)?->item(0);

        return $found instanceof DOMElement ? $found : null;
    }

    private function page(): string
    {
        if (self::$renderedPage !== null) {
            return self::$renderedPage;
        }

        $dashboardApiAuthRequired = true;
        ob_start();
        require dirname(__DIR__, 3) . '/src/Dashboard/index.php';

        return self::$renderedPage = (string)ob_get_clean();
    }
}
