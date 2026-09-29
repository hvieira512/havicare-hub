import { esc } from "../../format.js";

/**
 * As formas dos campos de alarme, partilhadas por quem os desenha e por quem os lê. Vivem
 * aqui e não num dos dois lados porque importar um do outro fechava um ciclo.
 */

/** A semana como se lê, e não como o fabricante a escreve. */
const WEEKDAYS = [
    { value: 1, label: "Seg" },
    { value: 2, label: "Ter" },
    { value: 3, label: "Qua" },
    { value: 4, label: "Qui" },
    { value: 5, label: "Sex" },
    { value: 6, label: "Sáb" },
    { value: 7, label: "Dom" },
];

/**
 * O selector de dias, um só para os quatro sítios que perguntam «que dias?».
 *
 * A ordem é sempre de segunda a domingo: a posição do domingo é do protocolo de cada
 * fabricante, e converte-se na fronteira.
 */
export function weekdayPicker(days, rowId) {
    const checked = new Set(
        (Array.isArray(days) ? days : [])
            .map((day) => parseInt(String(day), 10))
            .filter((day) => Number.isFinite(day)),
    );

    return `
        <label class="form-label-sm required">Dias</label>
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias da semana">
            ${WEEKDAYS.map((day) => `
                <input
                    class="btn-check"
                    type="checkbox"
                    id="${esc(rowId)}-day-${day.value}"
                    data-weekday
                    value="${day.value}"
                    ${checked.has(day.value) ? "checked" : ""}>
                <label class="btn btn-outline-secondary btn-sm" for="${esc(rowId)}-day-${day.value}">${day.label}</label>
            `).join("")}
        </div>`;
}

export function readWeekdays(row) {
    return Array.from(row.querySelectorAll("[data-weekday]:checked"))
        .map((input) => parseInt(String(input.value || ""), 10))
        .filter((day) => Number.isFinite(day) && day >= 1 && day <= 7)
        .sort((a, b) => a - b);
}

/** A máscara de sete posições do 4P Touch, com o domingo na posição 0. */
export function weekdaysToFourPTouchMask(days) {
    const positions = new Set(
        (Array.isArray(days) ? days : [])
            .map((day) => parseInt(String(day), 10))
            .filter((day) => day >= 1 && day <= 7)
            .map((day) => (day === 7 ? 0 : day)),
    );

    return Array.from({ length: 7 }, (_, index) => (positions.has(index) ? "1" : "0")).join("");
}

/** O caminho de volta: da máscara do 4P Touch para os dias de 1 a 7. @returns {number[]} */
export function fourPTouchMaskToWeekdays(mask) {
    const raw = String(mask || "").trim();
    if (!/^[01]{7}$/.test(raw)) {
        return [];
    }

    const days = [];
    for (let index = 0; index < 7; index += 1) {
        if (raw[index] === "1") {
            days.push(index === 0 ? 7 : index);
        }
    }

    return days.sort((a, b) => a - b);
}

/** A recorrência em palavras, que é o que a linha fechada mostra em vez da máscara de dias. */
export function recurrenceWords(kind, days) {
    if (kind !== "custom") {
        return kind === "daily" ? "todos os dias" : "uma vez";
    }

    const names = (Array.isArray(days) ? days : [])
        .map((day) => WEEKDAYS.find((weekday) => weekday.value === Number(day))?.label)
        .filter(Boolean)
        .map((label) => label.toLowerCase());

    return names.length > 0 ? names.join(", ") : "dias por escolher";
}

/**
 * A linha fechada de uma entrada de alarme: interruptor, título e valor à direita, com o
 * resto por baixo. O `name` agrupa as linhas da mesma lista e o browser fecha as outras.
 */
export function alarmDisclosure({ kind, group, control = "", summary: given, body, attrs = "" }) {
    const summary = alarmLineSlots(given);

    return `
        <div class="border rounded bg-body d-flex align-items-start gap-2 p-2" data-repeat-row="${esc(kind)}" ${attrs}>
            ${control === "" ? "" : `<div class="flex-shrink-0 pt-1">${control}</div>`}
            <details class="flex-grow-1 alarm-line-body" name="${esc(group)}">
                <summary class="alarm-line d-flex align-items-center gap-2">
                    <span class="alarm-line-main flex-grow-1">
                        <span class="d-block fw-semibold text-truncate" data-alarm-line-title>${esc(summary.title)}</span>
                        <span class="d-block small text-secondary text-truncate" data-alarm-line-subtitle>${esc(summary.subtitle)}</span>
                    </span>
                    <span class="small text-secondary flex-shrink-0" data-alarm-line-badges>${esc(summary.badges || "")}</span>
                    <span class="fw-semibold flex-shrink-0" data-alarm-line-trailing>${esc(summary.trailing)}</span>
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

/** O «acrescentar» de uma lista, com a conta do que lá está por baixo do texto. */
export function addAlarmButton(kind, label, count, limit) {
    return `
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="${esc(kind)}" ${count >= limit ? "disabled" : ""}>
                <i class="fa-solid fa-plus me-2"></i>${esc(label)} <span data-repeat-count>(${count} de ${limit})</span>
            </button>
        </div>`;
}

/** O rótulo do botão de rádio escolhido, que é o que o utilizador leu ao escolher. */
function checkedLabel(row, selector) {
    const checked = row.querySelector(selector);
    if (!checked) return "";
    return row.querySelector(`label[for="${checked.id}"]`)?.textContent.trim() ?? "";
}

const selectedLabel = (row, selector) => {
    const select = row.querySelector(selector);
    return select?.options?.[select.selectedIndex]?.textContent.trim() ?? "";
};

const fieldValue = (row, selector) => String(row.querySelector(selector)?.value || "").trim();

const TAKE_PILLS_FREQUENCIES = { 1: "once", 2: "daily", 3: "custom" };

/** O campo guarda a data em ISO; na linha fechada ela lê-se como cá se escreve. */
export function shortDate(value) {
    const parts = String(value || "").trim().split("-");
    return parts.length === 3 ? `${parts[2]}/${parts[1]}` : String(value || "").trim();
}

/**
 * O que enche cada encaixe da linha fechada, por tipo de lista. Aqui, e não espalhado por
 * cada fornecedor: o que muda entre eles são os campos que declaram, não a gramática.
 */
const ALARM_LINE_SUMMARIES = {
    alarm_clock: (row) => {
        const time = fieldValue(row, "[data-alarm-clock-field=\"time\"]");
        const kind = normalizeAlarmClockRecurrenceKind(
            fieldValue(row, "[data-alarm-clock-field=\"recurrenceKind\"]:checked") || "once",
        );

        return {
            title: fieldValue(row, "[data-alarm-clock-field=\"label\"]") ||
                checkedLabel(row, "[data-alarm-clock-field=\"type\"]:checked") ||
                time ||
                "Alarme por preencher",
            subtitle: recurrenceWords(kind, readWeekdays(row)),
            trailing: time,
            badges: fieldValue(row, "[data-alarm-clock-field=\"url\"]") === "" ? "" : "♪",
        };
    },
    takePillsReminder: (row) => {
        const section = row.closest("[data-config-section], [data-config-input]");
        const time = fieldValue(row, "[data-takepills-field=\"reminderTime\"]");
        const frequency = fieldValue(row, "[data-takepills-field=\"reminderFrequency\"]:checked") || "1";
        const text = section ? fieldValue(section, "[data-config-field=\"reminderText\"]") : "";
        const hasVoice = Boolean(section?.querySelector("[data-config-field=\"voiceEnabled\"]")?.checked) &&
            (section ? fieldValue(section, "[data-config-field=\"voiceData\"]") !== "" : false);

        return {
            title: text || time || "Lembrete por preencher",
            subtitle: recurrenceWords(TAKE_PILLS_FREQUENCIES[frequency] || "once", readWeekdays(row)),
            trailing: time,
            badges: hasVoice ? "🎤" : "",
        };
    },
    wonlexMedicationPlan: (row) => {
        const name = fieldValue(row, "[data-medication-field=\"drugName\"]");
        const dose = fieldValue(row, "[data-medication-field=\"drugDose\"]");
        const unit = selectedLabel(row, "[data-medication-field=\"drugUnit\"]");
        const times = [...row.querySelectorAll("[data-medication-period]:checked")]
            .map((period) => fieldValue(row, `[data-medication-period-time="${period.value}"]`))
            .filter((time) => time !== "")
            .sort();
        const end = fieldValue(row, "[data-medication-field=\"drugEndTime\"]");

        return {
            title: [name, dose === "" ? "" : `${dose} ${unit}`.trim()].filter(Boolean).join(" ") ||
                "Medicamento por preencher",
            subtitle: [times.join(" · "), end === "" ? "" : `até ${shortDate(end)}`].filter(Boolean).join(" · "),
            trailing: times.length === 0 ? "" : `${times.length}×/dia`,
            badges: "",
        };
    },
};

/** Volta a escrever a linha fechada a partir dos campos que estão abertos por baixo dela. */
export function refreshAlarmLine(row) {
    const summarize = ALARM_LINE_SUMMARIES[row?.dataset?.repeatRow || ""];
    if (!summarize) return;

    const summary = alarmLineSlots(summarize(row));
    for (const slot of ["title", "subtitle", "trailing", "badges"]) {
        const target = row.querySelector(`[data-alarm-line-${slot}]`);
        if (target) target.textContent = summary[slot];
    }
}

/** O teclado edita os campos abertos, e a linha fechada por cima tem de acompanhá-los. */
function refreshAlarmLineFrom(event) {
    const row = event.target?.closest?.("[data-repeat-row]");
    if (row) refreshAlarmLine(row);
}

if (typeof document !== "undefined") {
    document.addEventListener("input", refreshAlarmLineFrom, true);
    document.addEventListener("change", refreshAlarmLineFrom, true);
}

export function normalizeAlarmClockRecurrenceKind(value) {
    const raw = String(value || "").trim().toLowerCase();
    if (raw === "daily" || raw === "2") {
        return "daily";
    }
    if (raw === "custom" || raw === "3") {
        return "custom";
    }
    if (raw === "once" || raw === "1" || raw === "") {
        return "once";
    }
    return "once";
}

/** Os dias marcados, já na máscara que o 4P Touch espera. */
export function readFourPTouchAlarmDays(row) {
    return weekdaysToFourPTouchMask(readWeekdays(row));
}
