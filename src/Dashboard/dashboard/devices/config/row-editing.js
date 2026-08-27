import {renderPhoneControl, resetPhoneControls} from "../../phone.js";
import {takePillsReminderGroup} from "./index.js";
import {fourPTouchAlarmRow, wonlexMedicationPlanRow} from "./inputs.js";
import {syncTakePillsCustomVisibility} from "./take-pills-audio.js";

/**
 * Acrescentar, remover e renumerar as linhas repetíveis de uma secção de configuração:
 * contactos, listas telefónicas, alarmes, planos de medicação, lembretes.
 *
 * Nada disto toca no estado dos módulos da dashboard, e é isso que o torna testável à parte.
 */

export function appendContactRow(section) {
    const list = section.querySelector("[data-repeat-limit]");
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "10", 10);
    const rows = list.querySelectorAll('[data-repeat-row="contacts"]');
    if (rows.length >= limit) return;

    const isFourPTouchPhonebook = isFourPTouchPhonebookSection(section);
    const template = rows[rows.length - 1] || createContactRow({
        phonebook: isFourPTouchPhonebook,
        nameMaxLength: parseInt(section.dataset.phonebookNameMaxLength || (isFourPTouchPhonebook ? "10" : "0"), 10) || 0,
        phoneMaxLength: parseInt(section.dataset.phonebookPhoneMaxLength || (isFourPTouchPhonebook ? "20" : "0"), 10) || 0,
    });
    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((input) => {
        if (input.matches("[data-phone-local]")) {
            input.value = "";
            return;
        }
        input.value = "";
    });
    const countrySelect = clone.querySelector("[data-phone-country]");
    if (countrySelect) {
        countrySelect.value = "PT";
    }
    resetPhoneControls(clone);
    list.appendChild(clone);
}

export function appendPhoneListRow(section, rowType) {
    const list = section.querySelector(`[data-repeat-kind="${rowType}"]`);
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "10", 10);
    const rows = list.querySelectorAll(`[data-repeat-row="${rowType}"]`);
    if (rows.length >= limit) return;

    const template = rows[rows.length - 1] || null;
    if (!template) return;

    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((input) => {
        if (input.matches("[data-phone-local]")) {
            input.value = "";
            return;
        }
        input.value = "";
    });
    const countrySelect = clone.querySelector("[data-phone-country]");
    if (countrySelect) {
        countrySelect.value = "PT";
    }
    resetPhoneControls(clone);
    list.appendChild(clone);
}

export function appendAlarmClockRow(section) {
    const list = section.querySelector("[data-alarm-clock-list]");
    if (!list) return;

    const template = list.querySelector('[data-repeat-row="alarm_clock"]');
    if (!template) return;

    const clone = template.cloneNode(true);
    clone.querySelectorAll("input, select").forEach((input) => {
        if (input.matches('[data-alarm-clock-field="enabled"]')) {
            input.checked = true;
            return;
        }

        if (input.matches('[data-alarm-clock-field="recurrenceKind"]')) {
            input.checked = false;
            return;
        }

        if (input.matches('[data-alarm-clock-field="type"]')) {
            input.checked = input.value === "1";
            return;
        }

        if (input.type === "checkbox") {
            input.checked = false;
            return;
        }

        input.value = "";
    });
    const recurrenceInputs = Array.from(
        clone.querySelectorAll('[data-alarm-clock-field="recurrenceKind"]'),
    );
    const defaultRecurrence = recurrenceInputs.find(
        (input) => String(input.value || "").trim().toLowerCase() === "once",
    ) || recurrenceInputs[0];
    if (defaultRecurrence) defaultRecurrence.checked = true;

    syncAlarmClockCustomVisibility(clone);
    const switchLabel = clone.querySelector('[data-alarm-clock-field="enabled"]')
        ?.parentElement?.querySelector("[data-switch-label]");
    if (switchLabel) {
        switchLabel.textContent = "Ligado";
    }

    list.appendChild(clone);
}

/**
 * Desenha a linha em vez de a clonar: cada linha traz caixas de dias com `id` próprio, e um
 * clone repetia-os -- clicar num dia da linha nova mexia na primeira.
 */
export function appendFourPTouchAlarmRow(section) {
    const list = section.querySelector("[data-fourptouch-alarm-list]");
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "3", 10) || 3;
    const index = list.querySelectorAll('[data-repeat-row="fourPTouchAlarm"]').length;
    if (index >= limit) return;

    list.insertAdjacentHTML(
        "beforeend",
        fourPTouchAlarmRow({time: "", enabled: true, mode: 1, custom: ""}, index),
    );
    syncFourPTouchAlarmAddButton(section);
}

export function syncFourPTouchAlarmAddButton(section) {
    const list = section?.querySelector("[data-fourptouch-alarm-list]");
    const addButton = section?.querySelector(
        '[data-action="addFourPTouchAlarmRow"]',
    );
    if (!list || !addButton) return;

    const limit = parseInt(list.dataset.repeatLimit || "3", 10) || 3;
    addButton.disabled =
        list.querySelectorAll('[data-repeat-row="fourPTouchAlarm"]').length >= limit;
}

export function appendWonlexMedicationPlan(section) {
    const list = section.querySelector("[data-wonlex-medication-list]");
    if (!list) return;

    const index = list.querySelectorAll(
        '[data-repeat-row="wonlexMedicationPlan"]',
    ).length;
    list.insertAdjacentHTML(
        "beforeend",
        wonlexMedicationPlanRow({}, index),
    );
    renumberWonlexMedicationPlans(list);
}

export function appendTakePillsReminder(section) {
    const list = section.querySelector("[data-takepills-reminders-list]");
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "3", 10) || 3;
    const index = list.querySelectorAll(
        "[data-takepills-reminder-group]",
    ).length;
    if (index >= limit) return;

    list.insertAdjacentHTML(
        "beforeend",
        takePillsReminderGroup(
            {time: "08:00", enabled: true, frequency: 1, custom: ""},
            index,
            [
                {value: 1, label: "Uma vez"},
                {value: 2, label: "Diariamente"},
                {value: 3, label: "Personalizado"},
            ],
        ),
    );
    syncTakePillsRows(section);
}

export function removeTakePillsReminder(row) {
    const section = row?.closest("[data-config-section]");
    if (!row || !section) return;

    row.remove();
    syncTakePillsRows(section);
}

function syncTakePillsRows(section) {
    const list = section.querySelector("[data-takepills-reminders-list]");
    if (!list) return;

    const rows = list.querySelectorAll("[data-takepills-reminder-group]");
    rows.forEach((row, index) => {
        row.dataset.takepillsReminderGroup = String(index);
        const number = row.querySelector("[data-takepills-reminder-number]");
        if (number) {
            number.textContent = `Lembrete ${index + 1}`;
        }
        row.querySelectorAll("[data-takepills-index]").forEach((field) => {
            field.dataset.takepillsIndex = String(index);
        });
        const custom = row.querySelector("[data-takepills-custom-wrapper]");
        if (custom) {
            custom.dataset.takepillsCustomWrapper = String(index);
        }
    });

    const limit = parseInt(list.dataset.repeatLimit || "3", 10) || 3;
    const addButton = section.querySelector(
        '[data-action="addTakePillsReminder"]',
    );
    if (addButton) {
        addButton.disabled = rows.length >= limit;
    }
    syncTakePillsCustomVisibility(section);
}

export function removeWonlexMedicationPlan(row) {
    const list = row?.closest("[data-wonlex-medication-list]");
    if (!row || !list) return;

    row.remove();
    renumberWonlexMedicationPlans(list);
}

function renumberWonlexMedicationPlans(list) {
    list.querySelectorAll(
        '[data-repeat-row="wonlexMedicationPlan"]',
    ).forEach((row, index) => {
        const number = row.querySelector("[data-medication-plan-number]");
        if (number) {
            number.textContent = String(index + 1);
        }
    });
}

export function removeConfigRow(row) {
    if (!row) return;
    const parent = row.parentElement;
    if (!parent) return;
    if (parent.children.length <= 1) {
        row.querySelectorAll("input, select").forEach((input) => {
            if (input.matches('[data-alarm-clock-field="enabled"]')) {
                input.checked = true;
                return;
            }
            if (input.matches('[data-alarm-clock-field="recurrenceKind"]')) {
                input.checked = false;
                return;
            }
            if (input.matches('[data-alarm-clock-field="type"]')) {
                input.checked = input.value === "1";
                return;
            }
            if (input.type === "checkbox") {
                input.checked = false;
            } else if (input.matches("[data-phone-country]")) {
                input.value = "PT";
            } else {
                input.value = "";
            }
        });
        const recurrenceInputs = Array.from(
            row.querySelectorAll('[data-alarm-clock-field="recurrenceKind"]'),
        );
        const defaultRecurrence = recurrenceInputs.find(
            (input) => String(input.value || "").trim().toLowerCase() === "once",
        ) || recurrenceInputs[0];
        if (defaultRecurrence) defaultRecurrence.checked = true;
        syncAlarmClockCustomVisibility(row);
        resetPhoneControls(row);
        return;
    }
    row.remove();
}

export function syncAlarmClockCustomVisibility(row) {
    const customWrapper = row?.querySelector("[data-alarm-clock-custom-wrapper]");
    if (!customWrapper) return;

    const recurrence = row.querySelector('[data-alarm-clock-field="recurrenceKind"]:checked');
    customWrapper.classList.toggle(
        "d-none",
        String(recurrence?.value || "").trim().toLowerCase() !== "custom",
    );
}

function isFourPTouchPhonebookSection(section) {
    return String(section?.dataset?.configProtocol || "") === "four-p-touch"
        && String(section?.dataset?.configKey || "") === "phonebook";
}

function createContactRow({ phonebook = false, nameMaxLength = 0, phoneMaxLength = 0 } = {}) {
    const wrapper = document.createElement("div");
    wrapper.className = "row g-2 align-items-end";
    wrapper.dataset.repeatRow = "contacts";
    wrapper.innerHTML = `
        <div class="col-md-6">
            <input class="form-control" type="text" placeholder="Nome"${phonebook && nameMaxLength > 0 ? ` maxlength="${nameMaxLength}"` : ""} data-repeat-field="name">
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2">
                <div class="flex-grow-1">
                    ${renderPhoneControl({ repeatField: "phone", placeholder: "Telefone", maxLength: phoneMaxLength })}
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeContactRow">-</button>
            </div>
        </div>`;
    resetPhoneControls(wrapper);
    return wrapper;
}
