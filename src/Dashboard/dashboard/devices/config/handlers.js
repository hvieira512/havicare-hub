import {state} from "../../state.js";
import {syncPhoneControl} from "../../phone.js";
import {ruleFor, selectHubRuleValue} from "../hub-rules/index.js";
import {
    dismissConfigFeedback,
    renderDeviceConfigurationModal,
    saveDeviceConfiguration,
} from "./panel.js";
import {
    appendAlarmClockRow,
    appendContactRow,
    appendPhoneListRow,
    appendTakePillsReminder,
    appendWonlexMedicationPlan,
    removeConfigRow,
    removeTakePillsReminder,
    removeWonlexMedicationPlan,
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
 * Os handlers do painel de configuracao de um dispositivo.
 *
 * Tres eventos delegados na raiz do painel -- clique, `change` e `input` -- mais o fecho do
 * aviso de resultado, e as regras do hub, que vivem no mesmo painel mas gravam de imediato
 * em vez de entrarem no ciclo de vida dos downlinks.
 *
 * Do estado do `bootstrap.js` isto so precisa do `els`, e so em dois sitios: a raiz do
 * painel e o campo do IMEI.
 */
let els = {};

export function initDeviceConfigHandlers(context) {
    els = context.els;
}

/**
 * Grava uma regra do hub. Sem estado de entrega: nao ha nada a caminho de um dispositivo,
 * por isso o resultado e "Guardado" ou o erro, e nao "Enviado" nem "A espera".
 */
async function saveHubRule(key) {
    const rule = ruleFor(key);
    const block = els.deviceConfigRoot.querySelector(`[data-hub-rule="${key}"]`);
    if (!rule || !block) return;

    const data = (state.deviceModal.hubRules || {})[key] || {};
    const selection = rule.read(block, data);
    const invalid = rule.validate?.(selection);
    if (invalid) {
        setHubRuleFeedback(key, invalid, "danger");
        return;
    }

    const imei = String(state.deviceModal.imei || els.deviceImei?.value || "").trim();
    const error = await rule.save(imei, selection);
    if (error) {
        setHubRuleFeedback(key, error, "danger");
        return;
    }
    // Recarrega para o perfil derivado e as gamas vierem do servidor, e nao do que o
    // ecra supos: e o servidor que decide o nome do perfil a partir dos dois inteiros.
    state.deviceModal.hubRules = {
        ...(state.deviceModal.hubRules || {}),
        [key]: await rule.load(imei),
    };
    setHubRuleFeedback(key, "Guardado.", "success");
}

function setHubRuleFeedback(key, message, tone) {
    state.deviceModal.hubRuleFeedback = {
        ...(state.deviceModal.hubRuleFeedback || {}),
        [key]: {message, tone},
    };
    renderDeviceConfigurationModal();
}

function clearHubRuleFeedback(key) {
    if (!key || !state.deviceModal.hubRuleFeedback?.[key]) return;
    const next = {...state.deviceModal.hubRuleFeedback};
    delete next[key];
    state.deviceModal.hubRuleFeedback = next;
}

export function handleDeviceConfigClick(event) {
    // As regras do hub vivem no mesmo painel mas nao no ciclo de vida dos downlinks:
    // gravam de imediato e nao tem seccao de configuracao nem estado de entrega.
    const hubChoice = event.target.closest("[data-hub-rule-value]");
    if (hubChoice) {
        event.preventDefault();
        selectHubRuleValue(hubChoice.closest("[data-hub-rule]"), hubChoice.dataset.hubRuleValue);
        clearHubRuleFeedback(hubChoice.closest("[data-hub-rule]")?.dataset.hubRule);
        return;
    }

    const button = event.target.closest(
        "[data-config-category], [data-action]",
    );
    if (!button) return;

    if (button.dataset.action === "saveHubRule") {
        event.preventDefault();
        void saveHubRule(button.dataset.hubRuleKey);
        return;
    }
    if (button.dataset.action === "resetHubRule") {
        event.preventDefault();
        const key = button.dataset.hubRuleKey;
        const rule = ruleFor(key);
        if (rule) {
            selectHubRuleValue(
                els.deviceConfigRoot.querySelector(`[data-hub-rule="${key}"]`),
                rule.resetProfile,
            );
            clearHubRuleFeedback(key);
        }
        return;
    }

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

    if (button.dataset.action === "addContactRow") {
        appendContactRow(section);
        return;
    }

    if (button.dataset.action === "removeContactRow") {
        removeConfigRow(button.closest('[data-repeat-row="contacts"]'));
        return;
    }

    if (button.dataset.action === "addSosContactRow") {
        appendPhoneListRow(section, "sos_contacts");
        return;
    }

    if (button.dataset.action === "removeSosContactRow") {
        removeConfigRow(button.closest('[data-repeat-row="sos_contacts"]'));
        return;
    }

    if (button.dataset.action === "addWhitelistRow") {
        appendPhoneListRow(section, "call_whitelist");
        return;
    }

    if (button.dataset.action === "removeWhitelistRow") {
        removeConfigRow(button.closest('[data-repeat-row="call_whitelist"]'));
        return;
    }

    if (button.dataset.action === "addAlarmClockRow") {
        appendAlarmClockRow(section);
        return;
    }

    if (button.dataset.action === "removeAlarmClockRow") {
        removeConfigRow(button.closest('[data-repeat-row="alarm_clock"]'));
        return;
    }

    if (button.dataset.action === "addWonlexMedicationPlan") {
        appendWonlexMedicationPlan(section);
        return;
    }

    if (button.dataset.action === "addTakePillsReminder") {
        appendTakePillsReminder(section);
        return;
    }

    if (button.dataset.action === "removeTakePillsReminder") {
        removeTakePillsReminder(
            button.closest("[data-takepills-reminder-group]"),
        );
        return;
    }

    if (button.dataset.action === "removeWonlexMedicationPlan") {
        removeWonlexMedicationPlan(
            button.closest('[data-repeat-row="wonlexMedicationPlan"]'),
        );
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

function updateConfigChoice(section, button) {
    const field = String(button.dataset.configField || "");
    if (!field) return;

    const value = String(button.dataset.configValue || "");
    const input = section.querySelector(`[data-config-field="${field}"]`);
    if (!input) return;

    input.value = value;

    const group = button.closest("[data-config-choice-group]");
    if (!group) return;

    const buttons = group.querySelectorAll(
        '[data-action="selectConfigChoice"]',
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

    if (event.target.matches('[data-time-format="24h"]')) {
        normalizeTwentyFourHourTimeInput(event.target);
    }

    if (event.target.matches('[data-action="takePillsFile"]')) {
        const section = event.target.closest("[data-config-section]");
        if (!section) return;
        const file = event.target.files?.[0] || null;
        void loadTakePillsAudio(section, file);
        return;
    }

    const section = event.target.closest("[data-config-section]");
    if (!section) return;

    if (event.target.matches('[data-config-field="voiceEnabled"]')) {
        syncTakePillsVoiceVisibility(section);
    }

    if (event.target.matches('[data-takepills-field="reminderFrequency"]')) {
        syncTakePillsCustomVisibility(section);
    }

    if (event.target.matches("[data-medication-period]")) {
        const row = event.target.closest(
            '[data-repeat-row="wonlexMedicationPlan"]',
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

    if (event.target.matches('[data-config-field="mode"]')) {
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

    if (event.target.matches('[data-alarm-clock-field="recurrenceKind"]')) {
        const row = event.target.closest('[data-repeat-row="alarm_clock"]');
        if (row) {
            syncAlarmClockCustomVisibility(row);
        }
    }

    if (
        event.target.matches(
            '.form-check-input[type="checkbox"][role="switch"]',
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

    if (event.target.matches('[data-action="fallTotalLevels"]')) {
        const section = event.target.closest("[data-config-section]");
        if (!section) return;
        const total = parseInt(event.target.value, 10);
        const btns = section.querySelectorAll(
            '[data-config-choice-group="sensitivity"] .sens-level-btn',
        );
        const currentInput = section.querySelector(
            '[data-config-field="sensitivity"]',
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

    if (event.target.matches('[data-time-format="24h"]')) {
        normalizeTwentyFourHourTimeInput(event.target);
    }
}

function syncFourPTouchAlarmCustomVisibility(row) {
    if (!row) {
        return;
    }

    const mode = parseInt(
        String(row.querySelector('[data-fourptouch-field="mode"]')?.value ?? "1"),
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
