import { state } from "../../state.js";
import { syncPhoneControl } from "../../phone.js";
import {
    dismissConfigFeedback,
    renderDeviceConfigurationModal,
    saveDeviceConfiguration,
    saveDeviceConfigurationGroup,
    syncConfigGroupDirty,
    syncConfigSectionDirty,
} from "./panel.js";
import { appendRepeatRow, removeRepeatRow } from "./row-editing.js";
import { syncAlarmClockCustomVisibility } from "./inputs/capability.js";
import { syncFallSensitivityLevels } from "./inputs/four-p-touch.js";
import {
    normalizeTwentyFourHourTimeInput,
    syncSwitchLabel,
    updateConfigChoice,
} from "./inputs/shared.js";
import { syncWorkingModeExtra } from "./inputs/vivistar.js";
import { syncWonlexMedicationPeriod } from "./inputs/wonlex.js";
import {
    clearTakePillsRecording,
    loadTakePillsAudio,
    startTakePillsRecording,
    stopTakePillsRecording,
    syncTakePillsCustomVisibility,
    syncTakePillsVoiceVisibility,
} from "./take-pills-audio.js";

/**
 * Os handlers do painel de configuração de um dispositivo: três eventos delegados na raiz --
 * clique, `change` e `input` -- mais o fecho do aviso de resultado. Tudo o que precisam vem
 * do evento, e por isso este módulo não guarda `els` nenhum. O que fazem é encaminhar: as
 * regras de cada campo vivem com esse campo, e não aqui.
 */
export function handleDeviceConfigClick(event) {
    const button = event.target.closest(
        "[data-config-category], [data-action]",
    );
    if (!button) return;

    if (button.dataset.configCategory) {
        event.preventDefault();
        state.deviceModal.activeCategory = button.dataset.configCategory;
        renderDeviceConfigurationModal();
        return;
    }

    // Os interruptores agrupados enviam-se em conjunto: uma alteração por linha, um comando
    // por linha alterada, e um só pedido.
    const group = button.closest("[data-config-group]");
    if (group && button.dataset.action === "saveConfigGroup") {
        void saveDeviceConfigurationGroup(group);
        return;
    }
    if (group && button.dataset.action === "resetConfigGroup") {
        resetConfigGroup(group);
        syncConfigGroupDirty(group);
        return;
    }

    const section = button.closest("[data-config-section]");
    if (!section) return;

    if (button.dataset.action === "saveConfig") {
        // Os verbos de uma acção trazem o valor no próprio botão; o «Enviar» normal não traz
        // nenhum e o cartão continua a ler os seus campos.
        void saveDeviceConfiguration(section, button.dataset.actionValue || "");
        return;
    }

    if (button.dataset.action === "selectConfigChoice") {
        updateConfigChoice(section, button);
        return;
    }

    // As sete listas repetíveis passam pelo mesmo par: o tipo vem no botão ao acrescentar, e
    // da linha em que se está ao remover.
    if (button.dataset.action === "addRepeatRow") {
        appendRepeatRow(section, button.dataset.repeatKind || "");
        return;
    }

    if (button.dataset.action === "removeRepeatRow") {
        removeRepeatRow(button);
        // O botão sai do DOM com a linha, e o ouvinte lá fora já não lhe encontra a secção.
        syncConfigSectionDirty(section);
        return;
    }

    if (button.dataset.action === "takePillsRecord") {
        void startTakePillsRecording(section);
        return;
    }

    if (button.dataset.action === "takePillsStop") {
        void stopTakePillsRecording(section);
        return;
    }

    if (button.dataset.action === "takePillsClear") {
        clearTakePillsRecording(section);
    }
}

export function handleDeviceConfigChange(event) {
    if (event.target.matches("[data-phone-country]")) {
        syncPhoneControl(event.target);
        return;
    }

    if (event.target.matches("[data-time-format=\"24h\"]")) {
        normalizeTwentyFourHourTimeInput(event.target);
    }

    if (event.target.matches("[data-action=\"takePillsFile\"]")) {
        const section = event.target.closest("[data-config-section]");
        if (!section) return;
        const file = event.target.files?.[0] || null;
        void loadTakePillsAudio(section, file);
        return;
    }

    const section = event.target.closest("[data-config-section]");
    if (!section) return;

    if (event.target.matches("[data-config-field=\"voiceEnabled\"]")) {
        syncTakePillsVoiceVisibility(section);
    }

    if (event.target.matches("[data-takepills-field=\"reminderFrequency\"]")) {
        syncTakePillsCustomVisibility(section);
    }

    if (event.target.matches("[data-medication-period]")) {
        syncWonlexMedicationPeriod(event.target);
    }

    if (event.target.matches("[data-config-field=\"mode\"]")) {
        syncWorkingModeExtra(section, event.target.value);
    }

    if (event.target.matches("[data-alarm-clock-field=\"recurrenceKind\"]")) {
        const row = event.target.closest("[data-repeat-row=\"alarm_clock\"]");
        if (row) {
            syncAlarmClockCustomVisibility(row);
        }
    }

    if (
        event.target.matches(
            ".form-check-input[type=\"checkbox\"][role=\"switch\"]",
        )
    ) {
        syncSwitchLabel(event.target);
    }

    if (event.target.matches("[data-action=\"fallTotalLevels\"]")) {
        syncFallSensitivityLevels(section, event.target.value);
    }
}

export function handleDeviceConfigInput(event) {
    if (event.target.matches("[data-phone-local]")) {
        syncPhoneControl(event.target);
    }

    if (event.target.matches("[data-time-format=\"24h\"]")) {
        normalizeTwentyFourHourTimeInput(event.target);
    }
}

export function handleConfigFeedbackClosed(event) {
    const alertEl = event.target.closest("[data-config-feedback-key]");
    if (!alertEl) return;

    dismissConfigFeedback(alertEl.dataset.configFeedbackKey || "");
}

/**
 * Devolve as linhas do grupo ao valor com que foram desenhadas.
 *
 * O `type="reset"` de um formulário não serve aqui: as linhas não estão num formulário, e o
 * valor a repor é o que veio do hub e não o do atributo `checked` do HTML.
 */
function resetConfigGroup(group) {
    for (const row of group.querySelectorAll("[data-config-row]")) {
        let pristine;
        try {
            pristine = JSON.parse(row.dataset.configPristine || "{}");
        } catch {
            continue;
        }
        for (const input of row.querySelectorAll("[data-config-field]")) {
            const value = pristine[input.dataset.configField];
            if (input.type === "checkbox") {
                input.checked = value === true;
                continue;
            }
            input.value = value ?? "";
        }
    }
}
