<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DeviceRequestCardGroupingTest extends TestCase
{
    public function testDeviceRequestCardsAreGroupedIntoTelemetryAndSystemSections(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/detail-view.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('const TELEMETRY_REQUEST_GROUPS = [', $source);
        self::assertStringContainsString('label: "Telemetria"', $source);
        self::assertStringContainsString('label: "Informação do sistema"', $source);
        self::assertStringContainsString('"firmware_version"', $source);
        self::assertStringContainsString('"device_status"', $source);
        self::assertStringContainsString('filter(([, entry]) => entry?.supported)', $source);
        self::assertStringContainsString('renderRequestCardGroup(group, telemetry)', $source);
        self::assertStringContainsString('group.cards.length', $source);

        $renderersSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/renderers.js'
        );
        self::assertIsString($renderersSource);
        self::assertStringContainsString('const buttonHtml = requestable', $renderersSource);
        self::assertStringNotContainsString('if (!requestable) {', $renderersSource);
        self::assertStringContainsString('const isSystemRequestCard = [', $renderersSource);
        self::assertStringContainsString('firmware_version', $renderersSource);
        self::assertStringContainsString('device_status', $renderersSource);
        self::assertStringContainsString('const title = isSystemRequestCard', $renderersSource);
        self::assertStringContainsString('btn btn-primary btn-sm w-100', $renderersSource);
        self::assertStringContainsString('const buttonRowHtml = buttonHtml', $renderersSource);
        self::assertStringContainsString('mt-3 d-grid gap-2 min-w-0', $renderersSource);
        self::assertStringNotContainsString('mb-2 min-w-0', $renderersSource);
        self::assertStringContainsString('battery: {icon: "fa-battery-three-quarters"', $renderersSource);
        self::assertStringContainsString('activity: {icon: "fa-person-walking"', $renderersSource);
        self::assertStringContainsString('blood_sugar: {icon: "fa-vial"', $renderersSource);

        self::assertStringContainsString('const NCS_EVENT_CARD_TYPES = ["help_call", "reset"];', $source);
        self::assertStringContainsString('renderTelemetryList([...telemetry, ...ncsEvents]);', $source);
        self::assertStringContainsString('renderNcsEventCards(ncsEvents);', $source);
        self::assertStringContainsString('renderNcsEventCard({type, latest})', $source);
        self::assertStringContainsString('Eventos NCS recentes', file_get_contents(dirname(__DIR__, 3) . '/src/Dashboard/index.php'));
    }

    public function testFourPTouchSettingsModalUsesNativeEditors(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('requestAction: (entry) => requestActionInput(entry),', $source);
        self::assertStringContainsString('soundProfile: (_entry, desired) => soundProfileInput(desired),', $source);
        self::assertStringContainsString('function requestActionInput(entry)', $source);
        self::assertStringContainsString('function soundProfileInput(desired)', $source);
        self::assertStringContainsString('function isFourPTouchAlarmDaySelected(mask, day)', $source);
        self::assertStringContainsString('return ["0", "1", "2", "3", "4", "5", "6"]', $source);
        self::assertStringContainsString('function languageTimezoneInput(desired)', $source);
        self::assertStringContainsString('data-config-field="preset"', $source);
        self::assertStringContainsString('languageTimezonePresetOptions', $source);
        self::assertStringContainsString('English (UTC+0)', $source);
        self::assertStringContainsString('简体中文 (UTC+8)', $source);
        self::assertStringContainsString('Português (UTC+0)', $source);
        self::assertStringContainsString('Español (UTC+1)', $source);
        self::assertStringContainsString('Deutsch (UTC+1)', $source);
        self::assertStringContainsString('Français (UTC+1)', $source);
        self::assertStringContainsString('name="soundProfile"', $source);
        self::assertStringContainsString('data-config-field="mode"', $source);
        self::assertStringContainsString('role="radiogroup"', $source);
        self::assertStringContainsString('Vibração e toque', $source);
        self::assertStringContainsString('Só toque', $source);
        self::assertStringContainsString('Só vibração', $source);
        self::assertStringContainsString('Silêncio', $source);
        self::assertStringContainsString('sem parâmetros', $source);
        self::assertStringContainsString('4 modos', $source);
        self::assertStringContainsString('alarm_clock', $source);
        self::assertStringContainsString('data-alarm-clock-list', $source);
        self::assertStringContainsString('data-alarm-clock-field="recurrenceKind"', $source);
        self::assertStringContainsString('data-action="addAlarmClockRow"', $source);
        self::assertStringNotContainsString('data-reminders-list', $source);
        self::assertStringNotContainsString('addReminderRow', $source);
        self::assertStringNotContainsString('removeReminderRow', $source);
        self::assertStringContainsString('function capabilitySectionCandidates(entry)', $source);
        self::assertStringContainsString('alerts: "alarms"', $source);
        self::assertStringContainsString('system: "settings_system"', $source);
        self::assertStringContainsString('intervals: "settings_system"', $source);
    }

    public function testDeviceDetailFilterTypesAreDerivedFromObservedItems(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/detail-view.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('detailFilterTypesFromItems(', $source);
        self::assertStringContainsString('.filter((item) => item._source !== "command")', $source);
        self::assertStringContainsString('select.dataset.detailFilterTypesSignature', $source);
        self::assertStringContainsString('insertAdjacentHTML(', $source);
    }
}
