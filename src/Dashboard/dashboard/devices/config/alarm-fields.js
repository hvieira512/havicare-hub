import { html } from "../../core/html.js";
import { field } from "../../components/form-field.js";
import { optionLabel, weekdayLabel } from "../../core/i18n.js";
import { boolValue } from "./normalizers.js";
import { choiceField, nextUid } from "./inputs/shared.js";

/** A recorrência de que a API fala em todos os alarmes e lembretes. */
export const RECURRENCES = ["once", "daily", "custom"];

const DAYS = [1, 2, 3, 4, 5, 6, 7];

const recurrenceLabel = (kind) => optionLabel("alarm_clock", "", "recurrence", kind);

export function weekdayPicker(days, rowId) {
    const checked = new Set((Array.isArray(days) ? days : []).map(Number));

    return html`
        <label class="form-label-sm required">Dias</label>
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias da semana">
            ${DAYS.map((day) => html`
                <input class="btn-check" type="checkbox" id="${rowId}-day-${day}" data-weekday value="${day}" ${checked.has(day) ? "checked" : ""}>
                <label class="btn btn-outline-secondary btn-sm" for="${rowId}-day-${day}">${weekdayLabel(day)}</label>
            `)}
        </div>`;
}

export function readWeekdays(row) {
    return Array.from(row.querySelectorAll("[data-weekday]:checked"))
        .map((input) => Number(input.value))
        .filter((day) => day >= 1 && day <= 7)
        .sort((a, b) => a - b);
}

export function recurrenceWords(kind, days) {
    if (kind !== "custom") {
        return recurrenceLabel(kind).toLowerCase();
    }

    const names = (Array.isArray(days) ? days : []).map((day) => weekdayLabel(day).toLowerCase());
    return names.length > 0 ? names.join(", ") : "dias por escolher";
}

/** O `name` agrupa as linhas da mesma lista, e o browser fecha as outras. */
export function alarmDisclosure({ kind, group, control = "", summary: given, body }) {
    const summary = alarmLineSlots(given);

    return html`
        <div class="border rounded bg-body d-flex align-items-start gap-2 p-2" data-repeat-row="${kind}">
            ${control === "" ? "" : html`<div class="flex-shrink-0 pt-1">${control}</div>`}
            <details class="flex-grow-1 min-w-0 alarm-line-body" name="${group}">
                <summary class="alarm-line d-flex align-items-center gap-2">
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-truncate" data-alarm-line-title>${summary.title}</span>
                        <span class="d-block small text-secondary text-truncate" data-alarm-line-subtitle>${summary.subtitle}</span>
                    </span>
                    <span class="small text-secondary flex-shrink-0" data-alarm-line-badges>${summary.badges || ""}</span>
                    <span class="fw-semibold flex-shrink-0" data-alarm-line-trailing>${summary.trailing}</span>
                    <i class="fa-solid fa-chevron-down small text-secondary flex-shrink-0 alarm-line-chevron" aria-hidden="true"></i>
                </summary>
                <div class="pt-3">${body}</div>
            </details>
        </div>`;
}

/** Quem não tem nome fica com a hora por título, e aí repeti-la à direita não diz nada. */
function alarmLineSlots(summary) {
    return {
        title: summary.title || "",
        subtitle: summary.subtitle || "",
        badges: summary.badges || "",
        trailing: summary.trailing === summary.title ? "" : (summary.trailing || ""),
    };
}

export function addAlarmButton(kind, label, count, limit) {
    return html`
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="${kind}" ${count >= limit ? "disabled" : ""}>
                <i class="fa-solid fa-plus me-2"></i>${label} <span data-repeat-count>(${count} de ${limit})</span>
            </button>
        </div>`;
}

const value = (row, name) => String(row.querySelector(`[data-alarm-clock-field="${name}"]`)?.value || "").trim();
const checkedValue = (row, name) => String(row.querySelector(`[data-alarm-clock-field="${name}"]:checked`)?.value || "");

function checkedLabel(row, name) {
    const checked = row.querySelector(`[data-alarm-clock-field="${name}"]:checked`);
    return checked ? row.querySelector(`label[for="${checked.id}"]`)?.textContent.trim() ?? "" : "";
}

/**
 * Uma linha de alarme ou de lembrete: hora, recorrência, e o que a capacidade declarar de seu --
 * nome, tipo, som. O que não couber desce.
 */
export function alarmClockRow(item = {}, { recurrences = RECURRENCES, types = [], label = false, url = false, group = "alarm-clock", emptyTitle = "Alarme por preencher" } = {}) {
    const rowId = nextUid("alarm-clock");
    const kind = recurrences.includes(item.recurrence?.kind) ? item.recurrence.kind : recurrences[0];
    const days = item.recurrence?.days ?? [];
    const typeLabel = types.find((type) => String(type.value) === String(item.type))?.label ?? "";
    const control = html`
        <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" role="switch" aria-label="Ligado" data-alarm-clock-field="enabled" ${boolValue(item.enabled, true) ? "checked" : ""}>
        </div>`;
    const body = html`
        <div class="d-flex flex-wrap align-items-end gap-3">
            ${field("Hora", html`<input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-alarm-clock-field="time" value="${String(item.time ?? "")}" required>`, { cls: "alarm-field-time", required: true })}
            ${label ? field("Nome", html`<input class="form-control" type="text" data-alarm-clock-field="label" value="${String(item.label || "")}">`, { cls: "alarm-field-grow" }) : ""}
            ${field("Recorrência", choiceField({
                field: "recurrenceKind",
                value: kind,
                options: recurrences.map((recurrence) => ({ value: recurrence, label: recurrenceLabel(recurrence) })),
                attr: "data-alarm-clock-field",
                label: "Recorrência",
            }), { required: true })}
            ${types.length > 0 ? field("Tipo", choiceField({ field: "type", value: item.type ?? types[0].value, options: types, attr: "data-alarm-clock-field", label: "Tipo" }), { cls: "flex-shrink-0" }) : ""}
            <div class="w-100 ${kind === "custom" ? "" : "d-none"}" data-alarm-clock-custom-wrapper>${weekdayPicker(days, rowId)}</div>
            ${url ? field("Som", html`<input class="form-control" type="url" inputmode="url" placeholder="https://" data-alarm-clock-field="url" value="${String(item.url || "")}">`, { cls: "w-100" }) : ""}
            <div class="w-100 d-flex justify-content-end">
                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">
                    <i class="fa-solid fa-trash-can me-2"></i>Remover
                </button>
            </div>
        </div>`;

    return alarmDisclosure({
        kind: "alarm_clock",
        group,
        control,
        body,
        summary: {
            title: String(item.label || "") || typeLabel || String(item.time || "") || emptyTitle,
            subtitle: recurrenceWords(kind, days),
            trailing: String(item.time || ""),
            badges: String(item.url || "").trim() === "" ? "" : "♪",
        },
    });
}

/** As linhas preenchidas na forma pública; uma linha sem hora nunca chegou a ser preenchida. */
export function readAlarmRows(section) {
    return Array.from(section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]"))
        .map((row) => {
            const kind = checkedValue(row, "recurrenceKind") || "once";
            const item = {
                time: value(row, "time"),
                enabled: row.querySelector("[data-alarm-clock-field=\"enabled\"]")?.checked || false,
                recurrence: { kind },
            };
            for (const name of ["label", "url"]) {
                if (value(row, name) !== "") item[name] = value(row, name);
            }
            if (checkedValue(row, "type") !== "") item.type = Number(checkedValue(row, "type"));
            if (kind === "custom") {
                item.recurrence.days = readWeekdays(row);
                if (item.recurrence.days.length === 0) {
                    throw new Error("Selecione pelo menos um dia para a recorrência personalizada");
                }
            }

            return item;
        })
        .filter((item) => item.time !== "");
}

/** O título da linha fechada, refeito a cada tecla. */
export function refreshAlarmLine(row) {
    if (row?.dataset?.repeatRow !== "alarm_clock") return;

    const time = value(row, "time");
    const kind = checkedValue(row, "recurrenceKind") || "once";
    const reminderText = String(row.closest("[data-config-section]")?.querySelector("[data-config-field=\"reminderText\"]")?.value || "").trim();
    const summary = alarmLineSlots({
        title: value(row, "label") || checkedLabel(row, "type") || reminderText || time || "Alarme por preencher",
        subtitle: recurrenceWords(kind, readWeekdays(row)),
        trailing: time,
        badges: value(row, "url") === "" ? "" : "♪",
    });
    for (const slot of ["title", "subtitle", "trailing", "badges"]) {
        const target = row.querySelector(`[data-alarm-line-${slot}]`);
        if (target) target.textContent = summary[slot];
    }
}

/** Os dias da semana só se escolhem na recorrência personalizada. */
export function syncAlarmClockCustomVisibility(row) {
    row?.querySelector("[data-alarm-clock-custom-wrapper]")
        ?.classList.toggle("d-none", checkedValue(row, "recurrenceKind") !== "custom");
}

export function refreshAlarmLineFrom(event) {
    const row = event.target?.closest?.("[data-repeat-row]");
    if (row) refreshAlarmLine(row);
}
