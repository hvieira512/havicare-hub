import { fieldValue } from "../format.js";
import { loadScript } from "../load-script.js";

/**
 * Os sinais vitais de um radar ao lado da planta, com os gráficos do `live/info-panel.js` do
 * hitCare; os pontos vêm do histórico do stream e das leituras que chegam a seguir.
 */

const licenseIsland = globalThis.document?.getElementById("hub-amcharts-license");
const AMCHARTS_LICENSE = licenseIsland ? JSON.parse(licenseIsland.textContent) : "";

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

let charts = {};

/** O stream entrega enquanto o modal abre, e um render pode chegar antes do amCharts. */
function chartsReady() {
    return Boolean(globalThis.am5?.Root && globalThis.am5xy && globalThis.am5themes_Animated);
}

/**
 * O amCharts só este ecrã o usa, e entra quando se abre a planta. O `index.js` tem de estar
 * de pé antes do `xy.js` e do tema, que se registam nele.
 */
export async function loadCharts() {
    if (chartsReady()) return;

    // O erro do carregador traz o caminho do ficheiro, que não é linguagem de quem lê o aviso.
    try {
        await loadScript("/assets/vendor/amcharts5/index.js");
        await Promise.all([
            loadScript("/assets/vendor/amcharts5/xy.js"),
            loadScript("/assets/vendor/amcharts5/themes/Animated.js"),
        ]);
    } catch {
        throw new Error("Não foi possível carregar os gráficos dos sinais vitais.");
    }

    // Sem licença a biblioteca desenha o logótipo dela em cima de cada gráfico. Não é erro:
    // é o que o amCharts faz até alguém pôr a chave no ambiente.
    if (AMCHARTS_LICENSE) am5.addLicense(AMCHARTS_LICENSE);
}

/** Tira a grelha e as marcações de um eixo. Os rótulos ficam a cargo de quem chama. */
function stripAxis(axis) {
    const renderer = axis.get("renderer");
    renderer.grid.template.set("visible", false);
    renderer.ticks.template.set("visible", false);
}

function createChart(container, { color, unit }) {
    if (container.__am5root) container.__am5root.dispose();
    container.innerHTML = "";

    const root = am5.Root.new(container);
    container.__am5root = root;
    root._logo?.dispose();
    root.setThemes([am5themes_Animated.new(root)]);

    const chart = root.container.children.push(am5xy.XYChart.new(root, {
        layout: root.verticalLayout,
        paddingLeft: 0,
        paddingRight: 0,
        paddingTop: 4,
        // Seis pixéis e não zero, para o canto do cartão não cortar o rótulo mais baixo do eixo.
        paddingBottom: 6,
        // Sem zoom nem roda: é uma janela de minutos que anda sozinha, e não um gráfico
        // para explorar.
        wheelX: "none",
        wheelY: "none",
    }));
    // O `visible` não chega: o amCharts volta a mostrá-lo sempre que a série muda de âmbito.
    chart.zoomOutButton.set("forceHidden", true);

    // Sem eixos à vista, a tooltip é a única maneira de ler um ponto -- e por isso fica.
    const cursor = chart.set("cursor", am5xy.XYCursor.new(root, { behavior: "none", snapToSeriesBy: "x" }));
    cursor.lineX.set("visible", false);
    cursor.lineY.set("visible", false);

    // O tempo não leva rótulos: a janela são dezenas de segundos e as horas não dizem nada
    // que o «agora» do resto do ecrã não diga.
    const xAxis = chart.xAxes.push(am5xy.DateAxis.new(root, {
        baseInterval: { timeUnit: "second", count: 1 },
        renderer: am5xy.AxisRendererX.new(root, { strokeOpacity: 0 }),
        tooltipLocation: 0,
        maxDeviation: 0,
        groupData: false,
    }));
    stripAxis(xAxis);
    // `forceHidden` e não `visible`: invisível, o rótulo continua a reservar a altura dele.
    xAxis.get("renderer").labels.template.set("forceHidden", true);

    const yAxis = chart.yAxes.push(am5xy.ValueAxis.new(root, {
        // Ar por cima e por baixo da linha, para ela não ficar colada às bordas. Com uma
        // leitura constante o amCharts abre o intervalo por si, e a linha fica ao centro.
        extraMin: 0.4,
        extraMax: 0.4,
        // Batimentos e respirações contam-se por inteiros.
        maxPrecision: 0,
        renderer: am5xy.AxisRendererY.new(root, { strokeOpacity: 0 }),
    }));
    stripAxis(yAxis);
    // No tom da categoria, mas esbatidos, para não disputarem com o número grande do cabeçalho.
    yAxis.get("renderer").labels.template.setAll({
        fontSize: 11,
        fill: am5.color(color),
        fillOpacity: 0.65,
    });

    const series = chart.series.push(am5xy.SmoothedXLineSeries.new(root, {
        xAxis,
        yAxis,
        valueYField: "value",
        valueXField: "time",
        stroke: am5.color(color),
        fill: am5.color(color),
        strokeWidth: 2,
        tensionX: 0.8,
        tooltip: am5.Tooltip.new(root, {
            labelText: `{valueX.formatDate('HH:mm:ss')}\n[bold]{valueY} ${unit}[/]`,
        }),
    }));
    series.fills.template.setAll({ visible: true, fillOpacity: 0.2 });
    cursor.set("snapToSeries", [series]);

    return { root, series };
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
    if (!chartsReady()) return;

    Object.entries(PREFIX).forEach(([type, prefix]) => {
        const container = els[`${prefix}Chart`];
        if (!container) return;

        const points = seriesFrom(telemetry, type);
        charts[type] ??= createChart(container, VITALS[type]);
        charts[type].series.data.setAll(points);
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

export function destroyVitals() {
    Object.values(charts).forEach((chart) => chart.root.dispose());
    charts = {};
}

/**
 * O amCharts mede o contentor ao montar, e o modal só tem o tamanho final no `shown.bs.modal`,
 * de onde isto é chamado.
 */
export function resizeVitals(els, telemetry) {
    destroyVitals();
    renderVitals(els, telemetry);
}
