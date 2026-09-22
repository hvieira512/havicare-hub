import { html } from "../../html.js";

/**
 * Os cartões do sono: a noite e as pontuações que o firmware lhe atribui.
 *
 * A noite não é uma leitura -- é um relatório, com fronteiras, duração e os troços de cada
 * fase. O que cabe na linha é quanto se dormiu; o resto abre na gaveta.
 */

const PHASE_LABEL = {
    deep_sleep: "Profundo",
    light_sleep: "Leve",
    rem: "REM",
    insomnia: "Insónia",
    awake: "Acordado",
};

/** As fases pela ordem em que se lêem, e não pela ordem em que a noite as traz. */
const PHASE_ORDER = ["deep_sleep", "light_sleep", "rem", "insomnia", "awake"];

/** As estrelas da app do fabricante vão até cinco; um "3" sozinho não diz de quantas é. */
const QUALITY_STARS_MAX = 5;

const duration = (minutes) =>
    minutes >= 60
        ? `${Math.floor(minutes / 60)}h ${Math.round(minutes % 60)}m`
        : `${Math.round(minutes)} min`;

const clock = (value) => {
    const parsed = Date.parse(value ?? "");
    return Number.isNaN(parsed)
        ? ""
        : new Date(parsed).toLocaleTimeString("pt-PT", {
                hour: "2-digit",
                minute: "2-digit",
            });
};

const segments = (data) =>
    Array.isArray(data?.segments) ? data.segments : [];

/** Quanto tempo em cada fase, somado dos troços. */
function phaseMinutes(data) {
    const totals = new Map();
    for (const segment of segments(data)) {
        const minutes = Number(segment?.durationMinutes);
        if (!Number.isFinite(minutes)) continue;
        totals.set(segment?.type, (totals.get(segment?.type) ?? 0) + minutes);
    }

    return totals;
}

export function sleepValue(data) {
    const total = Number(data?.totalDurationMinutes);
    if (Number.isFinite(total) && total > 0) {
        return duration(total);
    }

    const slept = [...phaseMinutes(data).values()].reduce((sum, m) => sum + m, 0);
    return slept > 0 ? duration(slept) : "Sono sem duração";
}

export function sleepDetails(data) {
    const parts = [];

    const from = clock(data?.startTime);
    const to = clock(data?.endTime);
    if (from && to) {
        parts.push(html`Das ${from} às ${to}`);
    }

    const totals = phaseMinutes(data);
    for (const phase of PHASE_ORDER) {
        if (totals.has(phase)) {
            parts.push(html`${PHASE_LABEL[phase]}: ${duration(totals.get(phase))}`);
        }
    }

    const count = segments(data).length;
    if (count > 0) {
        parts.push(html`${count} ${count === 1 ? "troço" : "troços"}`);
    }

    return parts.join(" · ");
}

export function sleepQualityValue(data) {
    const stars = Number(data?.qualityStars);

    return Number.isFinite(stars)
        ? `${stars} de ${QUALITY_STARS_MAX} estrelas`
        : "Noite sem pontuação";
}
