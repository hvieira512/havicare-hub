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
    heart_rate: { field: "bpm", color: "#dc3545", unit: "bpm" },
    breath_rate: { field: "breathsPerMinute", color: "#0d6efd", unit: "rpm" },
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
const EXTRA = 0.4;

/** Pixéis entre linhas do eixo dos valores, e o ar da área do gráfico em cima e em baixo. */
const GRID_DISTANCE = 40;
const PLOT_PADDING = 4 + 6;

/** A altura mínima do `.radar-vital-chart`, enquanto o modal ainda não tem medida. */
const MIN_CHART_HEIGHT = 96;

const SECOND = 1000;

const clock = (time) => new Date(time).toLocaleTimeString("pt-PT");

const roundTo = (value, places) => Math.round(value * 10 ** places) / 10 ** places;

/** Escala de passos redondos (1, 2 ou 5 × 10ⁿ) e inteiros, como o `ValueAxis` do amCharts. */
function niceScale(min, max, gridCount, strict = false) {
    gridCount = Math.max(1, Math.round(gridCount));
    const [lowest, highest] = [min, max];
    const span = max - min || Math.abs(max);
    const magnitude = 10 ** Math.floor(Math.log10(Math.abs(span)));

    const unit = magnitude / 10;
    min = strict ? Math.floor(min / unit) * unit : Math.ceil(min / unit) * unit - unit;
    max = strict ? Math.ceil(max / unit) * unit : Math.floor(max / unit) * unit + unit;
    if (min < 0 && lowest >= 0) min = 0;

    let step = Math.ceil(span / gridCount / (magnitude / 100)) * (magnitude / 100);
    const power = 10 ** Math.floor(Math.log10(step));
    let factor = Math.ceil(step / power);
    factor = factor > 5 ? 10 : factor > 2 ? 5 : factor;
    step = Math.ceil(step / (power * factor)) * power * factor;
    step = Math.ceil(step) || 1;

    const places = power < 1 ? Math.round(Math.abs(Math.log10(power))) + 1 : 0;
    const first = Math.floor(min / step);
    let last = strict ? Math.floor(max / step) : Math.ceil(max / step);
    if (last === first) last++;
    min = roundTo(step * first, places);
    max = roundTo(step * last, places);
    if (max < highest) max += step;
    if (min > lowest) min -= step;

    return { min, max, step };
}

/** Uma leitura constante abre-se um décimo da ordem de grandeza para cada lado. */
function openUp(min, max) {
    if (max > min) return [min, max];
    const delta = 10 ** Math.floor(Math.log10(Math.abs(max))) / 10;

    return [min - delta, max + delta];
}

/** O eixo dos valores: o intervalo total, e dentro dele o que se mostra, como no amCharts. */
function valueScale(lowest, highest, gridCount) {
    let [min, max] = openUp(lowest, highest);
    min -= (max - min) * EXTRA;
    max += (max - min) * EXTRA;
    let total = niceScale(min, max, gridCount);
    total = niceScale(total.min, total.max, gridCount, true);

    if (lowest === highest) {
        ({ min, max } = niceScale(...openUp(lowest, highest), gridCount));
    } else {
        [min, max] = [lowest, highest];
    }
    const span = max - min;
    const shown = niceScale(min - span * EXTRA, max + span * EXTRA, gridCount);
    const clamp = (value) => Math.min(Math.max(value, total.min), total.max);

    return niceScale(clamp(shown.min), clamp(shown.max), gridCount, true);
}

/** A curva monótona em x do d3, a do `SmoothedXLineSeries`: nunca passa além de uma leitura. */
function monotonePath(points) {
    const xy = points.filter(([x, y], i) => i === 0 || x !== points[i - 1][0] || y !== points[i - 1][1]);
    const n = xy.length;
    const at = ([x, y]) => `${x} ${y}`;
    if (n < 3) return n === 1 ? `M ${at(xy[0])}` : `M ${at(xy[0])} L ${at(xy[1])}`;

    const sign = (value) => (value < 0 ? -1 : 1);
    const tangents = xy.map((_, i) => {
        if (i === 0 || i === n - 1) return 0;
        const [[xa, ya], [xb, yb], [xc, yc]] = xy.slice(i - 1, i + 2);
        const [h0, h1] = [xb - xa, xc - xb];
        const s0 = (yb - ya) / h0;
        const s1 = (yc - yb) / h1;
        const p = (s0 * h1 + s1 * h0) / (h0 + h1);
        return (sign(s0) + sign(s1)) * Math.min(Math.abs(s0), Math.abs(s1), 0.5 * Math.abs(p)) || 0;
    });
    const endTangent = (i, inner) => {
        const dx = xy[i + 1][0] - xy[i][0];
        return dx ? (3 * (xy[i + 1][1] - xy[i][1]) / dx - inner) / 2 : inner;
    };
    tangents[0] = endTangent(0, tangents[1]);
    tangents[n - 1] = endTangent(n - 2, tangents[n - 2]);

    const fixed = (value) => +value.toFixed(3);
    const curves = xy.slice(1).map(([x1, y1], i) => {
        const [x0, y0] = xy[i];
        const a = (x1 - x0) / 3;
        return `C ${fixed(x0 + a)} ${fixed(y0 + a * tangents[i])} ${fixed(x1 - a)} ${fixed(y1 - a * tangents[i + 1])} ${x1} ${y1}`;
    });

    return `M ${at(xy[0])} ${curves.join(" ")}`;
}

/** Cada leitura ocupa o seu segundo, com o ponto a meio: o `DateAxis` de intervalo base de um segundo. */
function plotPoints(points, scale) {
    const second = (time) => Math.floor(time / SECOND) * SECOND;
    const start = second(points[0].time);
    const span = second(points.at(-1).time) + SECOND - start;

    return points.map(({ time, value }) => [
        +((second(time) + SECOND / 2 - start) / span * 100).toFixed(3),
        +((scale.max - value) / (scale.max - scale.min) * 100).toFixed(3),
    ]);
}

/** As linhas da escala contam-se pela altura do cartão, e por isso redesenha-se quando ela muda. */
const drawn = new WeakMap();
const resizes = globalThis.ResizeObserver
    ? new ResizeObserver((entries) => entries.forEach(({ target }) => renderChart(target, ...drawn.get(target))))
    : null;

function renderChart(container, points, vital) {
    const { color, unit } = vital;
    drawn.set(container, [points, vital]);
    resizes?.observe(container);
    if (!points.length) {
        container.replaceChildren();
        return;
    }

    const values = points.map((point) => point.value);
    const plotHeight = (container.clientHeight || MIN_CHART_HEIGHT) - PLOT_PADDING;
    const scale = valueScale(Math.min(...values), Math.max(...values), plotHeight / GRID_DISTANCE);
    const xy = plotPoints(points, scale);
    const line = monotonePath(xy);
    const ticks = [];
    for (let value = scale.max; value > scale.min - scale.step / 2; value -= scale.step) {
        ticks.push(roundTo(value, 6));
    }

    container.innerHTML = html`<div class="radar-vital-frame" style="--radar-vital-color: ${color}">
        <div class="radar-vital-axis">${ticks.map((value) => html`<span style="top: ${(scale.max - value) / (scale.max - scale.min) * 100}%">${value}</span>`)}</div>
        <div class="radar-vital-plot">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <path class="radar-vital-fill" d="${line} L ${xy.at(-1)[0]} 100 L ${xy[0][0]} 100 Z"/>
                <path class="radar-vital-line" d="${line}"/>
            </svg>
            <div class="radar-vital-tooltip" hidden></div>
        </div>
    </div>`;

    const marks = points.map((point, i) => ({ ...point, x: xy[i][0], y: xy[i][1] }));
    const plot = container.querySelector(".radar-vital-plot");
    const tooltip = plot.querySelector(".radar-vital-tooltip");
    plot.addEventListener("pointermove", (event) => showTooltip(plot, tooltip, marks, unit, event));
    plot.addEventListener("pointerleave", () => {
        tooltip.hidden = true;
    });
}

/** A leitura mais próxima do cursor em x, numa caixa da cor da série que aponta para o ponto. */
function showTooltip(plot, tooltip, marks, unit, event) {
    const box = plot.getBoundingClientRect();
    if (!box.width) return;

    const x = (event.clientX - box.left) / box.width * 100;
    const mark = marks.reduce((best, candidate) => (Math.abs(candidate.x - x) < Math.abs(best.x - x) ? candidate : best));
    tooltip.innerHTML = html`${clock(mark.time)}<br><b>${mark.value} ${unit}</b>`;
    tooltip.hidden = false;

    // A caixa não sai do gráfico; quando encosta à borda, só o bico continua sobre o ponto.
    const left = mark.x / 100 * box.width;
    const top = mark.y / 100 * box.height;
    const half = tooltip.offsetWidth / 2;
    const boxLeft = Math.min(Math.max(left, half), box.width - half);
    tooltip.classList.toggle("radar-vital-tooltip-below", top < tooltip.offsetHeight + 4);
    tooltip.style.left = `${boxLeft}px`;
    tooltip.style.top = `${top}px`;
    tooltip.style.setProperty("--radar-vital-pointer", `${left - boxLeft}px`);
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
