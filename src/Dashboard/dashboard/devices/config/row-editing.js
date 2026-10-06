import { resetPhoneControls } from "../../phone.js";
import { refreshAlarmLine } from "./alarm-fields.js";
import {
    syncTakePillsRows,
    takePillsReminderGroup,
} from "./four-p-touch-take-pills.js";
import { syncAlarmClockCustomVisibility } from "./inputs/capability.js";
import { createContactRow } from "./inputs/generic.js";
import { nextUid } from "./inputs/shared.js";
import {
    renumberWonlexMedicationPlans,
    wonlexMedicationPlanRow,
} from "./inputs/wonlex.js";

/**
 * Linhas repetíveis de uma secção: `data-repeat-list="<tipo>"` no contentor, `data-repeat-row`
 * em cada linha e `data-repeat-limit` opcional.
 */

/**
 * `render` desenha a linha de novo; sem ele clona-se a última e limpa-se. `keepLast` limpa a
 * última em vez de a apagar, para haver sempre molde para clonar.
 */
const REPEAT_ROW_KINDS = {
    contacts: { keepLast: true, template: createContactRow },
    sos_contacts: { keepLast: true },
    call_whitelist: { keepLast: true },
    numbers: { keepLast: true },
    alarm_clock: { keepLast: true },
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
    // Sem `data-repeat-limit` não há limite.
    const limit = parseInt(list.dataset.repeatLimit || "", 10);
    if (Number.isFinite(limit) && rows.length >= limit) return;

    // O `restampRowIds` corre com a linha ainda fora do documento: o `resetRowFields` marca
    // rádios, e com os grupos por renomear desmarcaria os da linha irmã.
    let added = null;
    if (spec.render) {
        const holder = document.createElement("template");
        holder.innerHTML = spec.render(rows.length);
        for (const fresh of [...holder.content.children]) {
            restampRowIds(fresh);
            list.appendChild(fresh);
            added = fresh;
        }
    } else {
        const template = rows[rows.length - 1] || spec.template?.(section);
        if (!template) return;
        const clone = template.cloneNode(true);
        restampRowIds(clone);
        resetRowFields(clone);
        list.appendChild(clone);
        added = clone;
    }

    spec.after?.(section);
    syncAddButton(section, kind);
    refreshAlarmLine(added);
    openOnly(list, added);
}

/**
 * Renomeia os `id`, `name` e `for` de uma linha nova: repetidos, os rádios de duas linhas
 * formam um grupo só e as etiquetas apontam para as caixas da outra.
 */
function restampRowIds(row) {
    const suffix = nextUid("copy");
    const rename = (value) => `${value}-${suffix}`;

    for (const label of row.querySelectorAll("label[for]")) {
        label.htmlFor = rename(label.htmlFor);
    }
    for (const element of row.querySelectorAll("[id]")) {
        element.id = rename(element.id);
    }
    // O `name` de um `<details>` fica: é o grupo que o fecha quando uma irmã abre.
    for (const element of row.querySelectorAll("input[name], select[name], textarea[name]")) {
        element.name = rename(element.name);
    }
}

/** Só uma entrada aberta de cada vez, e a aberta é aquela em que se acabou de mexer. */
function openOnly(list, row) {
    const group = list.querySelector("details[name]")?.name || "";
    for (const details of list.querySelectorAll("details")) {
        details.open = false;
    }

    const opened = row?.querySelector("details");
    if (!opened) return;
    if (group !== "") opened.name = group;
    opened.open = true;
}

export function removeRepeatRow(button) {
    const row = button?.closest("[data-repeat-row]");
    const kind = row?.dataset.repeatRow || "";
    const spec = REPEAT_ROW_KINDS[kind];
    if (!row || !spec) return;

    const section = row.closest("[data-config-section]");
    if (spec.keepLast && row.parentElement?.children.length <= 1) {
        resetRowFields(row);
        refreshAlarmLine(row);
    } else {
        row.remove();
    }

    spec.after?.(section);
    syncAddButton(section, kind);
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
function syncAddButton(section, kind) {
    const list = section?.querySelector(`[data-repeat-list="${kind}"]`);
    const addButton = section?.querySelector(`[data-action="addRepeatRow"][data-repeat-kind="${kind}"]`);
    if (!list || !addButton) return;

    const limit = parseInt(list.dataset.repeatLimit || "", 10);
    const count = list.querySelectorAll(`[data-repeat-row="${kind}"]`).length;
    addButton.disabled = Number.isFinite(limit) && count >= limit;

    const counter = addButton.querySelector("[data-repeat-count]");
    if (counter && Number.isFinite(limit)) {
        counter.textContent = `(${count} de ${limit})`;
    }
}
