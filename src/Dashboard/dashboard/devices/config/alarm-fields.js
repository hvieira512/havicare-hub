import { esc } from "../../format.js";

/**
 * As formas dos campos de alarme, partilhadas por quem os desenha e por quem os lê. Vivem
 * aqui e não num dos dois lados porque importar um do outro fechava um ciclo.
 */

/** A semana como se lê, e não como o fabricante a escreve. */
const SEMANA = [
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
    const marcados = new Set(
        (Array.isArray(days) ? days : [])
            .map((day) => parseInt(String(day), 10))
            .filter((day) => Number.isFinite(day)),
    );

    return `
        <label class="form-label-sm required">Dias</label>
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias da semana">
            ${SEMANA.map((day) => `
                <input
                    class="btn-check"
                    type="checkbox"
                    id="${esc(rowId)}-day-${day.value}"
                    data-weekday
                    value="${day.value}"
                    ${marcados.has(day.value) ? "checked" : ""}>
                <label class="btn btn-outline-secondary btn-sm" for="${esc(rowId)}-day-${day.value}">${day.label}</label>
            `).join("")}
        </div>`;
}

/** Os dias marcados, de 1 a 7. @returns {number[]} */
export function readWeekdays(row) {
    return Array.from(row.querySelectorAll("[data-weekday]:checked"))
        .map((input) => parseInt(String(input.value || ""), 10))
        .filter((day) => Number.isFinite(day) && day >= 1 && day <= 7)
        .sort((a, b) => a - b);
}

/** A máscara de sete posições do 4P Touch, com o domingo na posição 0. */
export function weekdaysToFourPTouchMask(days) {
    const marcados = new Set(
        (Array.isArray(days) ? days : [])
            .map((day) => parseInt(String(day), 10))
            .filter((day) => day >= 1 && day <= 7)
            .map((day) => (day === 7 ? 0 : day)),
    );

    return Array.from({ length: 7 }, (_, index) => (marcados.has(index) ? "1" : "0")).join("");
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

export function formatFourPTouchAlarmTime(value) {
    const raw = String(value || "").trim();
    if (raw === "") {
        return "";
    }

    const hhmm = raw.replace(/[^0-9]/g, "");
    if (hhmm.length === 4) {
        return `${hhmm.slice(0, 2)}:${hhmm.slice(2, 4)}`;
    }

    if (/^\d{1,2}:\d{2}$/.test(raw)) {
        const [hour, minute] = raw.split(":");
        return `${String(parseInt(hour, 10)).padStart(2, "0")}:${String(parseInt(minute, 10)).padStart(2, "0")}`;
    }

    return raw;
}

/** Os dias marcados, já na máscara que o 4P Touch espera. */
export function readFourPTouchAlarmDays(row) {
    return weekdaysToFourPTouchMask(readWeekdays(row));
}
