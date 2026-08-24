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
        self::assertStringNotContainsString('"call_log"', $source);
        self::assertStringNotContainsString('"sms"', $source);
        self::assertStringNotContainsString('"device_state"', $source);
        self::assertStringNotContainsString('"ecg_analysis"', $source);
        self::assertStringContainsString('filter(([, entry]) => entry?.supported)', $source);
        // A faixa com o nome do grupo só existe quando há mais do que um grupo: num relógio,
        // que só tem "Telemetria", era uma moldura com um título por cima dos mosaicos,
        // dentro de um cartão que já se chama "Pedir dados".
        self::assertStringContainsString('groups.length > 1,', $source);
        self::assertStringContainsString('if (!showLabel) {', $source);
        self::assertStringContainsString('group.cards.length', $source);

        $renderersSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/renderers.js'
        );
        self::assertIsString($renderersSource);
        // O cartão é o pedido: a acção vive na casca do cartão e não num botão dentro
        // dele. Oito cartões davam oito botões primários escuros no mesmo painel, e o
        // alvo de clique era a parte mais pequena de uma área que já era toda clicável.
        self::assertStringContainsString('data-action="requestFeature" data-feature=', $renderersSource);
        self::assertStringContainsString('feature: requestable ? type : ""', $renderersSource);
        self::assertStringNotContainsString('btn btn-primary btn-sm w-100', $renderersSource);
        self::assertStringNotContainsString('if (!requestable) {', $renderersSource);
        self::assertStringContainsString('const isSystemRequestCard = [', $renderersSource);
        self::assertStringContainsString('firmware_version', $renderersSource);
        self::assertStringContainsString('device_status', $renderersSource);
        self::assertStringNotContainsString('call_log', $renderersSource);
        self::assertStringNotContainsString('"sms"', $renderersSource);
        self::assertStringNotContainsString('device_state', $renderersSource);
        self::assertStringNotContainsString('ecg_analysis', $renderersSource);
        // O título é sempre o nome da categoria; a leitura mais recente é um valor por
        // baixo. Substituí-lo pela leitura dava mosaicos a dizer "78%" e "Atenção" sem
        // dizer 78% de quê — perdia-se o nome exactamente quando havia o que mostrar.
        self::assertStringContainsString('const title = featureLabel(type) || card.value || type;', $renderersSource);
        self::assertStringContainsString('const value = isSystemRequestCard ? card.value : lastValue;', $renderersSource);
        // O estado do pedido em curso é a pastilha do sistema, não um spinner dentro de
        // um botão que já não existe.
        // A pastilha segue o estado do pedido, e não a chamada HTTP que o põe na fila.
        // "A pedir" durava o que durava essa chamada e desaparecia no instante em que o
        // pedido passava a existir: o mosaico esquecia-o exactamente quando havia algo para
        // dizer, e o único sítio que ainda sabia era a lista ao lado.
        self::assertStringContainsString('const REQUEST_CARD_STATE = {', $renderersSource);
        self::assertStringContainsString('function latestRequestState(', $renderersSource);
        // A hora de um pedido é `requestedAt`; ordená-los pelo `eventTime` dos eventos dava
        // zero em todos e o "mais recente" passava a ser o primeiro da lista.
        self::assertStringContainsString('function commandTime(command) {', $renderersSource);
        // `acked` fica de fora: a resposta já é o valor do mosaico.
        self::assertStringNotContainsString('acked: {label:', $renderersSource);
        self::assertStringContainsString('stateLabel: loading ? "a pedir" : requestState?.label || ""', $renderersSource);
        self::assertStringNotContainsString('spinner-border spinner-border-sm me-2', $renderersSource);
        self::assertStringNotContainsString('mb-2 min-w-0', $renderersSource);
        self::assertStringContainsString('battery: {icon: "fa-battery-three-quarters"', $renderersSource);
        self::assertStringContainsString('diaper_moisture: {icon: "fa-droplet"', $renderersSource);
        self::assertStringContainsString('diaper_moisture_level: {icon: "fa-percent"', $renderersSource);
        self::assertStringContainsString('diaper_condition: {icon: "fa-baby"', $renderersSource);
        // A marca de alerta sai do payload. Se alguem escrever o 40 aqui, e uma segunda copia
        // do limiar dos 4 canais que decide o estado no normalizador.
        self::assertStringContainsString('Number(data?.alertIndex)', $renderersSource);
        self::assertStringContainsString('change_required: "Mudança necessária"', $renderersSource);
        self::assertStringContainsString('activity: {icon: "fa-person-walking"', $renderersSource);
        self::assertStringContainsString('blood_sugar: {icon: "fa-vial"', $renderersSource);
        self::assertStringContainsString('superseded: "substituído"', $renderersSource);

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
        $source .= file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config/four-p-touch-take-pills.js'
        );
        // The input renderers moved out of config.js; the assertions below span
        // the dispatch table and the renderers it points at.
        $source .= file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config/inputs.js'
        );
        $source .= file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config/normalizers.js'
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
        self::assertStringContainsString('Português (UTC+1)', $source);
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
        self::assertStringContainsString('data-alarm-clock-field="label"', $source);
        self::assertStringContainsString('data-alarm-clock-field="url"', $source);
        self::assertStringNotContainsString('wonlexFields.labelRequired', $source);
        self::assertStringContainsString('Hora <span class="text-danger">*</span>', $source);
        self::assertStringContainsString('Recorrência <span class="text-danger">*</span>', $source);
        self::assertStringNotContainsString('data-reminders-list', $source);
        self::assertStringNotContainsString('addReminderRow', $source);
        self::assertStringNotContainsString('removeReminderRow', $source);
        self::assertStringContainsString('function capabilitySectionCandidates(entry)', $source);
        self::assertStringContainsString('const CONFIG_SECTION_ORDER = [', $source);
        self::assertStringContainsString('"health"', $source);
        self::assertStringContainsString('"settings_system"', $source);
        self::assertStringContainsString('assignCapabilitySection(entry, capabilityCatalog)', $source);
        self::assertStringNotContainsString('alerts: "alarms"', $source);
        self::assertStringNotContainsString('intervals: "settings_system"', $source);
    }

    public function testWonlexComplexSettingsUseGuidedFormsInsteadOfJson(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config.js'
        );
        $source .= file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config/four-p-touch-take-pills.js'
        );
        // The input renderers moved out of config.js; the assertions below span
        // the dispatch table and the renderers it points at.
        $source .= file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config/inputs.js'
        );
        $source .= file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config/normalizers.js'
        );
        $bootstrap = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/core/bootstrap.js'
        ) . file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/device-modal.js'
        ) . file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/config-panel.js'
        );

        self::assertIsString($source);
        self::assertIsString($bootstrap);
        self::assertStringContainsString('function capabilityDefinitionForKey(capabilityCatalog, capabilityKey)', $source);
        self::assertStringContainsString(
            '!definition?.isConfigurable && !definition?.isRequestable',
            $source
        );
        self::assertStringContainsString(
            'requestOnly:',
            $source
        );
        self::assertStringContainsString(
            'const showConfigurationBadge = !entry.requestOnly;',
            $source
        );
        self::assertStringContainsString(
            'const row = entry.requestOnly',
            $source
        );
        self::assertStringContainsString(
            'sectionLabel: String(definition.sectionLabel || section)',
            $source
        );
        self::assertStringContainsString(
            'group.entries[0]?.sectionLabel',
            $source
        );
        self::assertStringNotContainsString('CONFIG_SECTION_LABELS', $source);
        self::assertStringNotContainsString('Monitorização de saúde', $source);
        self::assertStringNotContainsString('Contactos e regras de chamadas', $source);
        self::assertStringContainsString('const CONFIGURATION_DELIVERY_META = {', $source);
        self::assertStringContainsString('retry_exhausted:', $source);
        self::assertStringContainsString('O último valor está guardado no Hub, mas não foi aplicado pelo dispositivo.', $source);
        self::assertStringContainsString('resolveConfigDelivery(entry, configurationSync)', $source);
        self::assertStringContainsString('renderConfigurationDeliveryNotice(deliveryMeta, delivery)', $source);
        self::assertStringContainsString('phonebook: (entry, desired, meta) => contactsInput(entry, desired, meta),', $source);
        self::assertStringContainsString(
            'toggleInput({...entry, fields: ["enabled"]}, desired)',
            $source
        );
        self::assertStringContainsString(
            'enabled: readCheckbox(section, "enabled")',
            $source
        );
        self::assertStringContainsString(
            'function toggleInput(entry, desired, protocol = "")',
            $source
        );
        self::assertStringContainsString(
            'desired.enabled ?? desired.switchState',
            $source
        );
        self::assertStringContainsString(
            'exerciseEnabled: readCheckbox(section, "exerciseEnabled")',
            $source
        );
        self::assertStringContainsString('data-sos-contact-phone', $source);
        self::assertStringContainsString('phonebookContacts: relatedConfigurations.phonebook || []', $source);
        self::assertStringContainsString('Apenas contactos existentes na lista telefónica podem ser usados como SOS.', $source);
        self::assertStringContainsString(
            'Selecione a escala de sensibilidade suportada pelo firmware (6 ou 8 níveis).',
            $source
        );
        self::assertStringContainsString('fallSensitivityLevels: () => ({sensitivity: 5, levels: 8})', $source);
        self::assertStringContainsString(': 8;', $source);
        self::assertStringContainsString('capabilityCatalog: state.deviceModal.capabilityCatalog', $bootstrap);
        self::assertStringContainsString('getCapabilities as apiGetCapabilities', $bootstrap);
        self::assertStringContainsString('entry.capabilityKey', $bootstrap);
        self::assertStringContainsString('enabledCapKeys.includes(entry.capabilityKey)', $bootstrap);
        self::assertStringContainsString(
            'configurationSync: state.deviceModal.configurationSync',
            $bootstrap
        );
        self::assertStringContainsString('onCommandsUpdated: syncDeviceModalCommandStates', $bootstrap);
        self::assertStringContainsString(
            'Valor guardado no Hub e enviado. A aguardar confirmação do dispositivo.',
            $bootstrap
        );
        self::assertStringContainsString(
            'wonlexMedicationPlans: (_entry, desired) => wonlexMedicationPlansInput(desired),',
            $source
        );
        self::assertStringContainsString('data-medication-field="drugName"', $source);
        self::assertStringContainsString('data-medication-period', $source);
        self::assertStringContainsString('Use 0 para desativar.', $source);
        self::assertStringNotContainsString(
            'numericValue(configuredValue, 0) <= 0',
            $source
        );
        self::assertStringContainsString('function readWonlexMedicationPlans(section)', $source);
        self::assertStringContainsString('data-action="addTakePillsReminder"', $source);
        self::assertStringContainsString('data-action="removeTakePillsReminder"', $source);
        self::assertStringContainsString('data-takepills-reminder-number', $source);
        self::assertStringContainsString('appendTakePillsReminder(section)', $bootstrap);
        self::assertStringContainsString('removeTakePillsReminder(', $bootstrap);
        self::assertStringContainsString('appendWonlexMedicationPlan(section)', $bootstrap);
        self::assertStringContainsString('[data-medication-period-time=', $bootstrap);
        self::assertStringNotContainsString('if (rows.length <= 1)', $bootstrap);
        self::assertStringNotContainsString('Adicione pelo menos um medicamento', $source);
        self::assertStringContainsString(
            'Nome do medicamento <span class="text-danger" aria-hidden="true">*</span>',
            $source
        );
        self::assertStringContainsString(
            'Períodos e horários <span class="text-danger" aria-hidden="true">*</span>',
            $source
        );
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

    public function testDeviceDetailFiltersCommandsByGenericFeatureBeforeNativeType(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/detail-view.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'if (item._source === "command" && p.feature) return p.feature;',
            $source
        );
        self::assertLessThan(
            strpos($source, 'if (p.nativeType) return p.nativeType;'),
            strpos($source, 'if (item._source === "command" && p.feature) return p.feature;')
        );
    }

    public function testDeviceSuppliersAreFilteredByDeviceTypeInTheSharedHelper(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/domain.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('const normalizedDeviceType = normalizeDeviceType(deviceType);', $source);
        self::assertStringNotContainsString('deviceType === "watch"', $source);
    }
}
