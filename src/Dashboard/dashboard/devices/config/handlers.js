import { state } from "../../state.js";
import { syncPhoneControl } from "../../phone.js";
import {
    dismissConfigFeedback,
    saveDeviceConfiguration,
    saveDeviceConfigurations,
    syncConfigCounts,
    syncConfigSectionDirty,
} from "./panel.js";
import { appendRepeatRow, removeRepeatRow } from "./row-editing.js";
import { syncLoadedCellsRunout } from "./loaded-cells-runout.js";
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
/** A raiz lê-se antes: há verbos que tiram o botão do DOM e depois já não se lá chega. */
export function handleDeviceConfigClick(event) {
    const root = event.target.closest?.("[data-config-root]") || null;
    dispatchConfigClick(event);
    if (root) syncConfigCounts(root);
}

function dispatchConfigClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;

    if (button.dataset.action === "selectConfigCategory") {
        event.preventDefault();
        const key = button.dataset.section || "";
        state.deviceModal.activeCategory = key;
        // Trocado no sítio e não redesenhado: as secções estão todas no DOM, e redesenhar
        // deitava fora o que estivesse escrito nas outras e ainda por enviar.
        selectConfigSection(button.closest("[data-config-root]"), key);
        return;
    }

    // A secção inteira envia-se de uma vez: uma alteração por definição, um comando por
    // definição alterada, e um só pedido.
    const pane = button.closest("[data-config-pane]");
    if (pane && button.dataset.action === "saveConfigPane") {
        void saveDeviceConfigurations(pane);
        return;
    }
    if (pane && button.dataset.action === "resetConfigPane") {
        resetConfigPane(pane);
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
    dispatchConfigChange(event);
    syncConfigCountsFrom(event.target);
}

function dispatchConfigChange(event) {
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

    if (event.target.matches("[data-config-field=\"cells\"]")) {
        syncLoadedCellsRunout(event.target);
    }

    syncConfigCountsFrom(event.target);
}

/** Acende a secção escolhida e abre o painel dela, sem tocar no que os outros têm escrito. */
export function selectConfigSection(root, key) {
    if (!root) return;

    for (const link of root.querySelectorAll("[data-config-section-link]")) {
        const selected = link.dataset.section === key;
        link.classList.toggle("selected", selected);
        link.setAttribute("aria-pressed", selected ? "true" : "false");
    }
    for (const pane of root.querySelectorAll("[data-config-pane]")) {
        const selected = pane.dataset.configPane === key;
        pane.classList.toggle("show", selected);
        pane.classList.toggle("active", selected);
    }
}

/** As contagens acompanham quem escreve: acertadas só ao desenhar, mentiam entre teclas. */
function syncConfigCountsFrom(target) {
    const root = target.closest?.("[data-config-root]");
    if (root) syncConfigCounts(root);
}

export function handleConfigFeedbackClosed(event) {
    const alertEl = event.target.closest("[data-config-feedback-key]");
    if (!alertEl) return;

    dismissConfigFeedback(alertEl.dataset.configFeedbackKey || "");
}

/**
 * Devolve as definições da secção ao valor com que foram desenhadas.
 *
 * O `type="reset"` de um formulário não serve aqui: as linhas de um grupo não estão num
 * formulário, e o valor a repor é o que veio do hub e não o do atributo `checked` do HTML.
 *
 * As listas repetíveis -- contactos, alarmes -- ficam de fora e mantêm o seu próprio repor:
 * uma linha que alguém acrescentou não se tira campo a campo.
 */
export function resetConfigPane(pane) {
    const blocks = pane.querySelectorAll("[data-config-row], [data-config-section]");
    for (const block of blocks) {
        if (block.querySelector("[data-repeat-list]")) continue;

        let pristine;
        try {
            pristine = JSON.parse(block.dataset.configPristine || "{}");
        } catch {
            continue;
        }
        for (const input of block.querySelectorAll("[data-config-field]")) {
            const value = pristine[input.dataset.configField];
            if (input.type === "checkbox") {
                input.checked = value === true;
                syncSwitchLabel(input);
                continue;
            }
            // Num grupo de rádios o `value` é a identidade da opção: o que se repõe é a marca.
            if (input.type === "radio") {
                input.checked = String(value ?? "") === input.value;
                continue;
            }
            input.value = value ?? "";
        }
    }
}

/**
 * O «Repor» de um bloco é o `type="reset"` do formulário dele: o browser devolve os campos ao
 * estado inicial sem disparar `change`, e as etiquetas dos interruptores ficavam a dizer o
 * contrário do que eles mostram.
 *
 * A reposição acontece depois dos ouvintes, e por isso a leitura espera pela microtarefa.
 */
export function handleDeviceConfigReset(event) {
    const form = event.target.closest?.("form") || event.target;

    queueMicrotask(() => {
        for (const input of form.querySelectorAll(
            ".form-check-input[type=\"checkbox\"][role=\"switch\"]",
        )) {
            syncSwitchLabel(input);
        }
        syncConfigCountsFrom(form);
    });
}
