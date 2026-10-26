import { resetPhoneControls } from "../phone.js";
import { refreshAlarmLine, syncAlarmClockCustomVisibility } from "./alarm-fields.js";
import { nextUid, syncSwitchLabel } from "./inputs/shared.js";

/** As listas repetíveis: clona-se a última linha e limpa-se; a última limpa-se em vez de sair. */
const REPEAT_ROW_KINDS = ["contacts", "sos_contacts", "call_whitelist", "numbers", "alarm_clock"];

export function appendRepeatRow(section, kind) {
    const list = section?.querySelector(`[data-repeat-list="${kind}"]`);
    if (!REPEAT_ROW_KINDS.includes(kind) || !list) return;

    const rows = list.querySelectorAll(`[data-repeat-row="${kind}"]`);
    const limit = parseInt(list.dataset.repeatLimit || "", 10);
    if (Number.isFinite(limit) && rows.length >= limit) return;

    // O `restampRowIds` corre com a linha ainda fora do documento: o `resetRowFields` marca
    // rádios, e com os grupos por renomear desmarcaria os da linha irmã.
    const template = rows[rows.length - 1];
    if (!template) return;
    const added = template.cloneNode(true);
    restampRowIds(added);
    resetRowFields(added);
    list.appendChild(added);

    syncAddButton(section, kind);
    refreshAlarmLine(added);
    openOnly(list, added);
}

/** Repetidos, os rádios de duas linhas formariam um grupo só e as etiquetas apontariam para a outra. */
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

/** Só uma entrada aberta de cada vez: aquela em que se acabou de mexer. */
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
    if (!row || !REPEAT_ROW_KINDS.includes(kind)) return;

    const section = row.closest("[data-config-section]");
    if (row.parentElement?.children.length <= 1) {
        resetRowFields(row);
        refreshAlarmLine(row);
    } else {
        row.remove();
    }

    syncAddButton(section, kind);
}

/** Os valores saem; o interruptor fica ligado e cada grupo de rádios na primeira escolha. */
function resetRowFields(row) {
    const firstOfGroup = new Set();
    row.querySelectorAll("input, select").forEach((input) => {
        if (input.type === "radio") {
            input.checked = !firstOfGroup.has(input.name);
            firstOfGroup.add(input.name);
        } else if (input.matches("[data-alarm-clock-field=\"enabled\"]")) {
            input.checked = true;
        } else if (input.type === "checkbox") {
            input.checked = false;
        } else if (input.matches("[data-phone-country]")) {
            input.value = "PT";
        } else {
            input.value = "";
        }
        if (input.type === "checkbox") syncSwitchLabel(input);
    });

    syncAlarmClockCustomVisibility(row);
    resetPhoneControls(row);
}

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
