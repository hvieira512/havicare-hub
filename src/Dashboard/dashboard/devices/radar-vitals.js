import { fieldValue } from "../format.js";
import { html } from "../html.js";

/**
 * Os sinais vitais de um radar ao lado da planta, com os gráficos do `live/info-panel.js` do
 * hitCare; os pontos vêm do histórico do stream e das leituras que chegam a seguir.
 */

/**
 * Sem limites de escala: fixá-los cola a linha ao fundo e esconde variações de poucos
 * batimentos.
 */
const VITALS = {
    heart_rate: { field: "bpm", tone: "danger", unit: "bpm" },
    breath_rate: { field: "breathsPerMinute", tone: "primary", unit: "rpm" },
};

/** Tons do Bootstrap a escurecer com a profundidade do sono; o desconhecido fica cinzento. */
const SLEEP_STATE = {
    awake: { tone: "success", icon: "fa-eye" },
    light_sleep: { tone: "primary", icon: "fa-moon" },
    deep_sleep: { tone: "dark", icon: "fa-bed" },
    unknown: { tone: "secondary", icon: "fa-circle-question" },
};

/** Quando o radar não reporta sono nenhum, dizê-lo é melhor do que um traço. */
const NO_SLEEP_READING = "Sem leitura do sono";

/** Ar por cima e por baixo da linha, em fração do intervalo, para não ficar colada às bordas. */
const Y_PADDING = 0.4;

const clock = (time) => new Date(time).toLocaleTimeString("pt-PT");

/**
 * A linha numa caixa de 100 por 100 que o SVG estica ao cartão. As curvas têm os pontos de
 * controlo à mesma altura dos extremos, e por isso nunca passam além de uma leitura.
 */
function sparklinePath(points) {
    if (points.length === 1) return "M 0 50 H 100";

    const values = points.map((point) => point.value);
    const min = Math.min(...values);
    const range = Math.max(...values) - min;
    const t0 = points[0].time;
    const span = points.at(-1).time - t0 || 1;
    const xy = points.map(({ time, value }) => [
        ((time - t0) / span) * 100,
        range ? 100 - ((value - min + range * Y_PADDING) / (range * (1 + 2 * Y_PADDING))) * 100 : 50,
    ].map((n) => +n.toFixed(2)));

    return xy.map(([x, y], i) => {
        if (i === 0) return `M ${x} ${y}`;
        const [px, py] = xy[i - 1];
        const mx = +((px + x) / 2).toFixed(2);
        return `C ${mx} ${py} ${mx} ${y} ${x} ${y}`;
    }).join(" ");
}

function renderChart(container, points, { tone, unit }) {
    if (!points.length) {
        container.replaceChildren();
        return;
    }

    const values = points.map((point) => point.value);
    const [min, max] = [Math.min(...values), Math.max(...values)];
    const range = min === max ? `${min} ${unit}` : `${min}–${max} ${unit}`;
    const line = sparklinePath(points);

    container.innerHTML = html`<svg class="radar-vital-sparkline text-${tone}" viewBox="0 0 100 100" preserveAspectRatio="none" role="img">
        <title>${range}, das ${clock(points[0].time)} às ${clock(points.at(-1).time)}</title>
        <path d="${line} L 100 100 L 0 100 Z" fill="currentColor" fill-opacity=".2"/>
        <path d="${line}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/>
    </svg>`;
}

/**
 * As leituras de um tipo, da mais antiga para a mais recente. O zero fica de fora: é o radar a
 * dizer que não deteta ninguém.
 */
function seriesFrom(telemetry, type) {
    const { field } = VITALS[type];

    return telemetry
        .filter((row) => row?.type === type)
        .map((row) => ({
            time: new Date(row.occurredAt || row.recordedAt).getTime(),
            value: Number(row?.data?.[field] ?? 0),
        }))
        .filter((point) => Number.isFinite(point.time) && point.value > 0)
        .sort((a, b) => a.time - b.time);
}

/** O mínimo, a média e o máximo são da janela que está no gráfico, e não do dia. */
function renderSummary(els, prefix, points) {
    const values = points.map((point) => point.value);
    const last = values.at(-1);

    els[`${prefix}Value`].textContent = last === undefined ? "--" : String(last);
    els[`${prefix}Min`].textContent = values.length ? String(Math.min(...values)) : "--";
    els[`${prefix}Max`].textContent = values.length ? String(Math.max(...values)) : "--";
    els[`${prefix}Avg`].textContent = values.length
        ? String(Math.round(values.reduce((total, value) => total + value, 0) / values.length))
        : "--";
}

const PREFIX = { heart_rate: "radarHeartRate", breath_rate: "radarBreathRate" };

/** Desenha os dois gráficos e a faixa do sono a partir do histórico que o stream trouxe. */
export function renderVitals(els, telemetry) {
    Object.entries(PREFIX).forEach(([type, prefix]) => {
        const container = els[`${prefix}Chart`];
        if (!container) return;

        const points = seriesFrom(telemetry, type);
        renderChart(container, points, VITALS[type]);
        renderSummary(els, prefix, points);
    });

    renderSleepState(els, telemetry);
}

function renderSleepState(els, telemetry) {
    const latest = telemetry.find((row) => row?.type === "sleep_state");
    const state = String(latest?.data?.state || "unknown");
    const { tone, icon } = SLEEP_STATE[state] || SLEEP_STATE.unknown;

    els.radarSleepStateIcon.className = `fa-solid ${icon} fa-lg`;
    els.radarSleepStateLabel.textContent = latest
        ? fieldValue("sleep_state", state)
        : NO_SLEEP_READING;

    // Só o tom: a disposição da faixa é do `radar-map.php` e não se reescreve daqui. A lista
    // copia-se antes de se mexer nela -- remover de dentro de um percurso salta entradas.
    const banner = els.radarSleepState;
    [...banner.classList]
        .filter((name) => name.startsWith("text-bg-"))
        .forEach((name) => banner.classList.remove(name));
    banner.classList.add(`text-bg-${tone}`);
}
