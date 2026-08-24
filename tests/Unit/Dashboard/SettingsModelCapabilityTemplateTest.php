<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class SettingsModelCapabilityTemplateTest extends TestCase
{
    public function testModelCapabilitiesAreBoundToSupplierTemplateInTheSettingsEditor(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/capabilities.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'const visibleSections = sections',
            $source,
        );
        self::assertStringContainsString('const templateSet = new Set(', $source);
        self::assertStringContainsString(
            'state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(',
            $source,
        );
        self::assertStringContainsString(
            'visibleEntries.length === 0',
            $source,
        );
        self::assertStringNotContainsString(
            'bg-white ${!supported ? "opacity-50" : ""}',
            $source,
        );
        self::assertStringContainsString(
            'state.settingsModal.capabilityModelTemplateKeys =',
            $source,
        );
        self::assertStringContainsString(
            'tmpl.enabledCapabilities.map(String);',
            $source,
        );
        self::assertStringContainsString(
            'model.requestableCapabilities.map(String)',
            $source,
        );
        self::assertStringContainsString(
            'data-action="toggleCapabilitySupport"',
            $source,
        );
        self::assertStringContainsString(
            'data-action="toggleCapabilityRequestability"',
            $source,
        );
        self::assertStringContainsString(
            'model.requestableCapabilityKeys.map(String)',
            $source,
        );
        self::assertStringContainsString(
            'capabilityLabelByKey(',
            $source,
        );
        self::assertStringContainsString(
            'state.settingsModal.capabilityCatalog,',
            $source,
        );
        // Um controlo por eixo. «Apenas receção» era um badge e «Solicitável neste
        // modelo» um interruptor, para a mesma pergunta -- o modelo aceita pedido? --
        // e só uma das duas formas se podia mudar. São sempre dois interruptores, e
        // quando o protocolo não suporta pedido é a etiqueta que diz a razão.
        self::assertStringContainsString(
            'for="requestable-${esc(feature)}">Solicitável</label>',
            $source,
        );
        self::assertStringContainsString(
            'não suporta pedido',
            $source,
        );
        self::assertStringNotContainsString(
            '<span class="badge text-bg-secondary">Apenas receção</span>',
            $source,
        );
        self::assertStringContainsString(
            'body.append("requestableCapabilitiesConfigured", "1");',
            $source,
        );
    }
}
