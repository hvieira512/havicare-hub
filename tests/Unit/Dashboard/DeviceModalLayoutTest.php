<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * O arranjo do modal do dispositivo: os dois separadores na horizontal, a conta das
 * alterações por enviar, e o «Eliminar» fora do rodapé.
 */
final class DeviceModalLayoutTest extends TestCase
{
    private static ?string $renderedPage = null;

    private static ?DOMDocument $document = null;

    public function testTheTwoTabsSitInOneHorizontalRow(): void
    {
        $nav = $this->element('deviceConfigTabBtn')->parentNode;
        self::assertInstanceOf(DOMElement::class, $nav);

        $classes = (string)$nav->getAttribute('class');
        self::assertStringNotContainsString('flex-lg-column', $classes);
        self::assertStringContainsString('flex-row', $classes);
    }

    public function testTheContentTakesTheTwelveColumns(): void
    {
        $content = $this->query('//*[contains(concat(" ", @class, " "), " device-modal-content ")]');
        self::assertInstanceOf(DOMElement::class, $content);

        self::assertStringNotContainsString('col-lg-10', (string)$content->getAttribute('class'));
    }

    public function testTheConfigTabCarriesTheUnsentCount(): void
    {
        $count = $this->element('deviceConfigCount');

        self::assertSame('deviceConfigTabBtn', $count->parentNode?->getAttribute('id'));
        self::assertStringContainsString('d-none', (string)$count->getAttribute('class'));
    }

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
