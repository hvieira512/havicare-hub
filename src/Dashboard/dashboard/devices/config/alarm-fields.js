/**
 * As formas dos campos de alarme, partilhadas por quem os desenha e por quem os lê. Vivem
 * aqui e não num dos dois lados porque importar um do outro fechava um ciclo.
 */

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

export function readAlarmClockDays(row) {
    return Array.from(
        row.querySelectorAll("[data-alarm-clock-day=\"customDays\"]:checked"),
    )
        .map((input) => parseInt(String(input.value || ""), 10))
        .filter((day) => Number.isFinite(day) && day >= 1 && day <= 7);
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

export function readFourPTouchAlarmDays(row) {
    const selected = new Set(
        Array.from(row.querySelectorAll("[data-fourptouch-day=\"customDays\"]:checked"))
            .map((input) => String(input.value || ""))
            .filter(Boolean),
    );

    return ["0", "1", "2", "3", "4", "5", "6"]
        .map((day) => (selected.has(day) ? "1" : "0"))
        .join("");
}
