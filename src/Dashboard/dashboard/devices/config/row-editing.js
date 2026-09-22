import { resetPhoneControls } from "../../phone.js";
import {
    syncTakePillsRows,
    takePillsReminderGroup,
} from "./four-p-touch-take-pills.js";
import { syncAlarmClockCustomVisibility } from "./inputs/capability.js";
import { createContactRow } from "./inputs/generic.js";
import {
    renumberWonlexMedicationPlans,
    wonlexMedicationPlanRow,
} from "./inputs/wonlex.js";

/**
 * Acrescentar e remover as linhas repetíveis de uma secção de configuração.
 *
 * Um contrato só para as sete listas: `data-repeat-list="<tipo>"` no contentor,
 * `data-repeat-row="<tipo>"` em cada linha, e o limite opcional em `data-repeat-limit`. Não
 * toca no estado dos módulos da dashboard, o que o torna testável à parte.
 *
 * O que cada tipo de linha sabe de si -- desenhá-la, renumerá-la, mantê-la coerente -- vem do
 * módulo do campo a que pertence, e é aqui declarado no `REPEAT_ROW_KINDS`.
 */

/**
 * Como nasce e como morre a linha de cada tipo.
 *
 * `render` desenha-a de novo, preciso quando a linha traz `id` próprios -- um clone
 * repetia-os. Sem ele, clona-se a última e limpa-se, o que preserva o que a marcação trouxe.
 *
 * `keepLast` limpa a última em vez de a apagar: sem linha nenhuma não há molde para clonar.
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
    syncAddButton(section, kind);
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
    // O botão é `addRepeatRow` distinguido pelo `data-repeat-kind`; procurar um `data-action`
    // com o nome do tipo não casava com nada, e o sync saía sempre sem tocar no botão.
    const addButton = section?.querySelector(`[data-action="addRepeatRow"][data-repeat-kind="${kind}"]`);
    if (!list || !addButton) return;

    const limit = parseInt(list.dataset.repeatLimit || "", 10);
    addButton.disabled = Number.isFinite(limit) &&
        list.querySelectorAll(`[data-repeat-row="${kind}"]`).length >= limit;
}
