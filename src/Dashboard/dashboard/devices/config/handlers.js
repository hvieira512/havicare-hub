import { state } from "../../core/state.js";
import { syncPhoneControl } from "../phone.js";
import {
    dismissConfigFeedback,
    saveDeviceConfiguration,
    saveDeviceConfigurations,
    syncConfigCounts,
    syncConfigSectionDirty,
} from "./panel.js";
import { appendRepeatRow, removeRepeatRow } from "./row-editing.js";
import { syncLoadedCellsRunout } from "./loaded-cells-runout.js";
import { refreshAlarmLineFrom, syncAlarmClockCustomVisibility } from "./alarm-fields.js";
import { syncFallSensitivityLevels } from "./inputs/four-p-touch.js";
import {
    applyConfigPreset,
    normalizeTwentyFourHourTimeInput,
    syncSwitchLabel,
} from "./inputs/shared.js";
import { syncWorkingModeExtra } from "./inputs/vivistar.js";
import { handleScaleUserAction } from "./inputs/scale.js";
import { applyFactoryBoundary, syncSwitchValueDim } from "./inputs/radar.js";
import { openRadarMap } from "../radar/radar-map-modal.js";
import {
    clearTakePillsRecording,
    loadTakePillsAudio,
    startTakePillsRecording,
    stopTakePillsRecording,
    syncTakePillsVoiceVisibility,
} from "./take-pills-audio.js";

/** A raiz lê-se antes: há verbos que tiram o botão do DOM e depois já não se lá chega. */
export function handleDeviceConfigClick(event) {
    const root = event.target.closest?.("[data-config-root]") || null;
    dispatchConfigClick(event);
    if (root) syncConfigCounts(root);
}

const PANE = "[data-config-pane]";
const SECTION = "[data-config-section]";

/** Os verbos do painel pelo `data-action`: o `within` diz o contentor de que o verbo precisa. */
const CLICK_ACTIONS = {
    selectConfigCategory: {
        run: (button, _button, event) => {
            event.preventDefault();
            const key = button.dataset.section || "";
            state.deviceModal.activeCategory = key;
            state.deviceModal.configSectionOpen = true;
            // Trocado no sítio: redesenhar deitava fora o que está escrito nas outras secções.
            selectConfigSection(button.closest("[data-config-root]"), key);
        },
    },
    closeConfigSection: {
        run: (button) => {
            state.deviceModal.configSectionOpen = false;
            button.closest("[data-config-root]")?.removeAttribute("data-config-section-open");
        },
    },
    // A secção inteira num só pedido, com um comando por definição alterada.
    saveConfigPane: { within: PANE, run: (pane) => void saveDeviceConfigurations(pane) },
    resetConfigPane: { within: PANE, run: (pane) => resetConfigPane(pane) },
    // Os verbos de uma acção trazem o valor no botão; o «Enviar» lê os campos.
    saveConfig: {
        within: SECTION,
        run: (section, button) => void saveDeviceConfiguration(section, button.dataset.actionValue || ""),
    },
    openRadarAreas: {
        within: SECTION,
        run: () => void openRadarMap(String(state.deviceModal.imei || ""), { editAreas: true }),
    },
    // O tipo da lista vem do botão ao acrescentar, e da linha ao remover.
    addRepeatRow: { within: SECTION, run: (section, button) => appendRepeatRow(section, button.dataset.repeatKind || "") },
    removeRepeatRow: {
        within: SECTION,
        run: (section, button) => {
            removeRepeatRow(button);
            // O botão sai do DOM com a linha, e o ouvinte lá fora já não lhe encontra a secção.
            syncConfigSectionDirty(section);
        },
    },
    takePillsRecord: { within: SECTION, run: (section) => void startTakePillsRecording(section) },
    takePillsStop: { within: SECTION, run: (section) => void stopTakePillsRecording(section) },
    takePillsClear: { within: SECTION, run: (section) => clearTakePillsRecording(section) },
};

function dispatchConfigClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const action = String(button.dataset.action || "");

    if (action.startsWith("scaleUser")) {
        const section = button.closest(SECTION);
        if (section && handleScaleUserAction(section, button)) syncConfigSectionDirty(section);
        return;
    }

    const verb = CLICK_ACTIONS[action];
    const scope = verb?.within ? button.closest(verb.within) : button;
    if (verb && scope) verb.run(scope, button, event);
}

export function handleDeviceConfigChange(event) {
    refreshAlarmLineFrom(event);
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

    if (event.target.matches("[data-config-preset]")) {
        applyConfigPreset(section, event.target);
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
        syncSwitchValueDim(section);
    }

    if (section.dataset.configInput === "radarInstallation" && event.target.type === "radio") {
        applyFactoryBoundary(section.closest("[data-config-pane]"), event.target.value);
    }

    if (event.target.matches("[data-action=\"fallTotalLevels\"]")) {
        syncFallSensitivityLevels(section);
    }
}

export function handleDeviceConfigInput(event) {
    refreshAlarmLineFrom(event);
    if (event.target.matches("[data-phone-local]")) {
        syncPhoneControl(event.target);
    }

    if (event.target.matches("[data-time-format=\"24h\"]")) {
        normalizeTwentyFourHourTimeInput(event.target);
    }

    if (event.target.matches("[data-config-field=\"cells\"]")) {
        syncLoadedCellsRunout(event.target);
    }

    // A barra e o número da sensibilidade são o mesmo valor.
    for (const twin of event.target.closest("[data-twin-fields]")?.querySelectorAll(`[data-config-field="${CSS.escape(event.target.dataset.configField || "")}"]`) ?? []) {
        if (twin !== event.target) twin.value = event.target.value;
    }

    syncConfigCountsFrom(event.target);
}

export function selectConfigSection(root, key) {
    if (!root) return;

    root.setAttribute("data-config-section-open", "");
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

function syncConfigCountsFrom(target) {
    const root = target.closest?.("[data-config-root]");
    if (root) syncConfigCounts(root);
}

export function handleConfigFeedbackClosed(event) {
    const alertEl = event.target.closest("[data-config-feedback-key]");
    if (!alertEl) return;

    dismissConfigFeedback(alertEl.dataset.configFeedbackKey || "");
}

/** Devolve as definições ao valor desenhado; as listas repetíveis têm o seu próprio repor. */
function restoreDrawnFields(block) {
    let drawn;
    try {
        drawn = JSON.parse(block.dataset.configPristineFields ?? "null");
    } catch {
        return false;
    }
    const inputs = [...block.querySelectorAll("[data-config-field]")];
    if (!Array.isArray(drawn) || drawn.length !== inputs.length) return false;

    inputs.forEach((input, index) => {
        if (input.type === "checkbox" || input.type === "radio") {
            input.checked = drawn[index] === true;
            if (input.type === "checkbox") syncSwitchLabel(input);
            return;
        }
        input.value = String(drawn[index] ?? "");
    });

    return true;
}

export function resetConfigPane(pane) {
    const blocks = pane.querySelectorAll("[data-config-row], [data-config-section]");
    for (const block of blocks) {
        if (block.querySelector("[data-repeat-list]")) continue;
        if (restoreDrawnFields(block)) {
            syncSwitchValueDim(block);
            continue;
        }

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
