import { state } from "../../state.js";
import { syncPhoneControl } from "../../phone.js";
import {
    dismissConfigFeedback,
    renderDeviceConfigurationModal,
    saveDeviceConfiguration,
} from "./panel.js";
import {
    appendRepeatRow,
    removeRepeatRow,
    syncAlarmClockCustomVisibility,
} from "./row-editing.js";
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
 * do evento, e por isso este módulo não guarda `els` nenhum.
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

    const section = button.closest("[data-config-section]");
    if (!section) return;

    if (button.dataset.action === "saveConfig") {
        void saveDeviceConfiguration(section);
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

/** Escreve cada campo do preset e recalcula qual dos botões fica aceso. */
function applyConfigPreset(section, button) {
    let preset;
    try {
        preset = JSON.parse(button.dataset.configPreset);
    } catch {
        return;
    }

    for (const [field, value] of Object.entries(preset || {})) {
        const input = section.querySelector(`[data-config-field="${field}"]`);
        if (input) input.value = String(value);
    }

    const group = button.closest("[data-config-choice-group]");
    for (const choice of group?.querySelectorAll("[data-config-preset]") || []) {
        const active = choice === button;
        choice.classList.toggle("active", active);
        choice.setAttribute("aria-pressed", active ? "true" : "false");
    }
}

function updateConfigChoice(section, button) {
    // Um preset preenche mais do que um campo de uma vez -- a sensibilidade das fraldas são
    // dois inteiros --, e o botão carrega o par em vez de um valor só. O estado activo lê-se
    // dos campos: nenhum preset activo já diz que os valores não são de nenhum deles.
    if (button.dataset.configPreset) {
        applyConfigPreset(section, button);
        return;
    }

    const field = String(button.dataset.configField || "");
    if (!field) return;

    const value = String(button.dataset.configValue || "");
    const input = section.querySelector(`[data-config-field="${field}"]`);
    if (!input) return;

    input.value = value;

    const group = button.closest("[data-config-choice-group]");
    if (!group) return;

    const buttons = group.querySelectorAll(
        "[data-action=\"selectConfigChoice\"]",
    );
    buttons.forEach((choice) => {
        const selected =
            String(choice.dataset.configField || "") === field &&
            String(choice.dataset.configValue || "") === value;
        choice.classList.toggle("active", selected);
        choice.setAttribute("aria-pressed", selected ? "true" : "false");
    });
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
        const row = event.target.closest(
            "[data-repeat-row=\"wonlexMedicationPlan\"]",
        );
        const periodTime = row?.querySelector(
            `[data-medication-period-time="${event.target.value}"]`,
        );
        if (periodTime) {
            periodTime.disabled = !event.target.checked;
            if (event.target.checked && String(periodTime.value || "") === "") {
                periodTime.value = "08:00";
            }
        }
    }

    if (event.target.matches("[data-config-field=\"mode\"]")) {
        const extra = section.querySelector("[data-working-mode-extra]");
        if (extra) {
            extra.classList.toggle(
                "d-none",
                String(event.target.value) !== "8",
            );
        }
        const alarmRow = event.target.closest("[data-fourptouch-alarm-row]");
        if (alarmRow) {
            syncFourPTouchAlarmCustomVisibility(alarmRow);
        }
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
        const label = event.target.parentElement?.querySelector(
            "[data-switch-label]",
        );
        if (label) {
            label.textContent = event.target.checked
                ? label.dataset.switchOn || "Ligado"
                : label.dataset.switchOff || "Desligado";
        }
    }

    if (event.target.matches("[data-action=\"fallTotalLevels\"]")) {
        const section = event.target.closest("[data-config-section]");
        if (!section) return;
        const total = parseInt(event.target.value, 10);
        const btns = section.querySelectorAll(
            "[data-config-choice-group=\"sensitivity\"] .sens-level-btn",
        );
        const currentInput = section.querySelector(
            "[data-config-field=\"sensitivity\"]",
        );
        btns.forEach((btn, i) => {
            const visible = i + 1 <= total;
            btn.classList.toggle("d-none", !visible);
            btn.disabled = !visible;
        });
        if (currentInput && parseInt(currentInput.value, 10) > total) {
            const lastEnabled = Array.from(btns).find(
                (btn) => !btn.classList.contains("d-none") && !btn.disabled,
            );
            if (lastEnabled) {
                currentInput.value = String(
                    parseInt(lastEnabled.dataset.configValue || "1", 10) || 1,
                );
                btns.forEach((btn) => {
                    const selected =
                        btn.dataset.configValue === currentInput.value;
                    btn.classList.toggle("active", selected);
                    btn.setAttribute(
                        "aria-pressed",
                        selected ? "true" : "false",
                    );
                });
            }
        }
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

function syncFourPTouchAlarmCustomVisibility(row) {
    if (!row) {
        return;
    }

    const mode = parseInt(
        String(row.querySelector("[data-fourptouch-field=\"mode\"]")?.value ?? "1"),
        10,
    ) || 1;
    const custom = row.querySelector("[data-fourptouch-custom-wrapper]");
    if (custom) {
        custom.classList.toggle("d-none", mode !== 3);
    }
}

function normalizeTwentyFourHourTimeInput(input) {
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    const digits = String(input.value || "").replace(/[^0-9]/g, "").slice(0, 4);
    if (digits.length === 0) {
        input.value = "";
        return;
    }
    if (digits.length <= 2) {
        input.value = digits;
        return;
    }
    input.value = `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

export function handleConfigFeedbackClosed(event) {
    const alertEl = event.target.closest("[data-config-feedback-key]");
    if (!alertEl) return;

    dismissConfigFeedback(alertEl.dataset.configFeedbackKey || "");
}
