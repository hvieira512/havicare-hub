import { renderPhoneControl, resetPhoneControls } from "../../phone.js";
import { takePillsReminderGroup } from "./index.js";
import { fourPTouchAlarmRow, wonlexMedicationPlanRow } from "./inputs.js";
import { syncTakePillsCustomVisibility } from "./take-pills-audio.js";

/**
 * Acrescentar e remover as linhas repetíveis de uma secção de configuração: contactos,
 * listas telefónicas, alarmes, planos de medicação, lembretes.
 *
 * Um contrato só para as sete listas: `data-repeat-list="<tipo>"` no contentor,
 * `data-repeat-row="<tipo>"` em cada linha, e o limite opcional em `data-repeat-limit`.
 * O que difere de tipo para tipo é só o que está na tabela abaixo.
 *
 * Nada disto toca no estado dos módulos da dashboard, e é isso que o torna testável à parte.
 */

/**
 * Como nasce e como morre a linha de cada tipo.
 *
 * `render` desenha-a de novo, que é o que é preciso quando a linha traz `id` próprios -- um
 * clone repetia-os, e clicar num dia da linha nova mexia na primeira. Sem `render`, clona-se
 * a última e limpa-se, que é o que preserva o que a marcação trouxe da secção (os
 * comprimentos máximos da lista telefónica, por exemplo).
 *
 * `keepLast` diz se a última linha se limpa em vez de se apagar: uma lista que fica sem
 * linha nenhuma fica também sem molde de onde clonar a seguinte.
 */
const REPEAT_ROW_KINDS = {
    contacts: { keepLast: true, template: createContactRow },
    sos_contacts: { keepLast: true },
    call_whitelist: { keepLast: true },
    numbers: { keepLast: true },
    alarm_clock: { keepLast: true },
    fourPTouchAlarm: {
        render: (index) => fourPTouchAlarmRow({ time: "", enabled: true, mode: 1, custom: "" }, index),
        after: syncFourPTouchAlarmRows,
    },
    wonlexMedicationPlan: {
        render: (index) => wonlexMedicationPlanRow({}, index),
        after: renumberWonlexMedicationPlans,
    },
    takePillsReminder: {
        render: (index) => takePillsReminderGroup(
            { time: "08:00", enabled: true, frequency: 1, custom: "" },
            index,
            [
                { value: 1, label: "Uma vez" },
                { value: 2, label: "Diariamente" },
                { value: 3, label: "Personalizado" },
            ],
        ),
        after: syncTakePillsRows,
    },
};

export function appendRepeatRow(section, kind) {
    const spec = REPEAT_ROW_KINDS[kind];
    const list = section?.querySelector(`[data-repeat-list="${kind}"]`);
    if (!spec || !list) return;

    const rows = list.querySelectorAll(`[data-repeat-row="${kind}"]`);
    // Sem `data-repeat-limit` não há limite: os alarmes do relógio nunca tiveram um.
    const limit = parseInt(list.dataset.repeatLimit || "", 10);
    if (Number.isFinite(limit) && rows.length >= limit) return;

    if (spec.render) {
        list.insertAdjacentHTML("beforeend", spec.render(rows.length));
    } else {
        const template = rows[rows.length - 1] || spec.template?.(section);
        if (!template) return;
        const clone = template.cloneNode(true);
        resetRowFields(clone);
        list.appendChild(clone);
    }

    spec.after?.(section);
}

export function removeRepeatRow(button) {
    const row = button?.closest("[data-repeat-row]");
    const kind = row?.dataset.repeatRow || "";
    const spec = REPEAT_ROW_KINDS[kind];
    if (!row || !spec) return;

    const section = row.closest("[data-config-section]");
    if (spec.keepLast && row.parentElement?.children.length <= 1) {
        resetRowFields(row);
    } else {
        row.remove();
    }

    spec.after?.(section);
}

/**
 * Limpa uma linha: os valores saem, e o que tem um estado inicial próprio -- o interruptor
 * de ligado, a recorrência, o indicativo do telefone -- volta a ele.
 */
function resetRowFields(row) {
    row.querySelectorAll("input, select").forEach((input) => {
        if (input.matches("[data-alarm-clock-field=\"enabled\"]")) {
            input.checked = true;
            return;
        }
        if (input.matches("[data-alarm-clock-field=\"recurrenceKind\"]")) {
            input.checked = false;
            return;
        }
        if (input.matches("[data-alarm-clock-field=\"type\"]")) {
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
        row.querySelectorAll("[data-alarm-clock-field=\"recurrenceKind\"]"),
    );
    const defaultRecurrence = recurrenceInputs.find(
        (input) => String(input.value || "").trim().toLowerCase() === "once",
    ) || recurrenceInputs[0];
    if (defaultRecurrence) defaultRecurrence.checked = true;

    const switchLabel = row.querySelector("[data-alarm-clock-field=\"enabled\"]")
        ?.parentElement?.querySelector("[data-switch-label]");
    if (switchLabel) {
        switchLabel.textContent = "Ligado";
    }

    syncAlarmClockCustomVisibility(row);
    resetPhoneControls(row);
}

/** O botão de acrescentar apaga-se no limite. */
function syncAddButton(section, kind, action) {
    const list = section?.querySelector(`[data-repeat-list="${kind}"]`);
    const addButton = section?.querySelector(`[data-action="${action}"]`);
    if (!list || !addButton) return;

    const limit = parseInt(list.dataset.repeatLimit || "", 10);
    addButton.disabled = Number.isFinite(limit) &&
        list.querySelectorAll(`[data-repeat-row="${kind}"]`).length >= limit;
}

function syncFourPTouchAlarmRows(section) {
    syncAddButton(section, "fourPTouchAlarm", "addFourPTouchAlarmRow");
}

/** Os lembretes levam o seu número em cinco sítios, e removê-los desalinha-os todos. */
function syncTakePillsRows(section) {
    const list = section?.querySelector("[data-repeat-list=\"takePillsReminder\"]");
    if (!list) return;

    list.querySelectorAll("[data-repeat-row=\"takePillsReminder\"]").forEach((row, index) => {
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

    syncAddButton(section, "takePillsReminder", "addTakePillsReminder");
    syncTakePillsCustomVisibility(section);
}

function renumberWonlexMedicationPlans(section) {
    section
        ?.querySelectorAll("[data-repeat-row=\"wonlexMedicationPlan\"]")
        .forEach((row, index) => {
            const number = row.querySelector("[data-medication-plan-number]");
            if (number) {
                number.textContent = String(index + 1);
            }
        });
}

export function syncAlarmClockCustomVisibility(row) {
    const customWrapper = row?.querySelector("[data-alarm-clock-custom-wrapper]");
    if (!customWrapper) return;

    const recurrence = row.querySelector("[data-alarm-clock-field=\"recurrenceKind\"]:checked");
    customWrapper.classList.toggle(
        "d-none",
        String(recurrence?.value || "").trim().toLowerCase() !== "custom",
    );
}

function isFourPTouchPhonebookSection(section) {
    return String(section?.dataset?.configProtocol || "") === "four-p-touch" &&
        String(section?.dataset?.configKey || "") === "phonebook";
}

/** A primeira linha de contactos, quando a lista veio vazia e não há de onde clonar. */
function createContactRow(section) {
    const phonebook = isFourPTouchPhonebookSection(section);
    const nameMaxLength = parseInt(
        section?.dataset.phonebookNameMaxLength || (phonebook ? "10" : "0"), 10,
    ) || 0;
    const phoneMaxLength = parseInt(
        section?.dataset.phonebookPhoneMaxLength || (phonebook ? "20" : "0"), 10,
    ) || 0;

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
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeRepeatRow">-</button>
            </div>
        </div>`;
    resetPhoneControls(wrapper);
    return wrapper;
}
