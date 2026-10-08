import { capabilityLabel } from "../capability-catalog.js";
import { rowPayload, when } from "../format.js";
import { html } from "../html.js";
import { renderInto } from "../dom.js";
import {
    resetDetailFiltersDraft,
    setDownlinkPage,
    setTelemetryPage,
    state,
    updateDetailFiltersDraft,
} from "../state.js";
import { resolvePaginationPage } from "../pagination.js";
import { uplinkCardContent } from "../components/cards/telemetry.js";
import { filterChips } from "../components/chips.js";

/**
 * Filtros e paginadores do histórico de um dispositivo (a listagem é do `list-filters.js`). O
 * que redesenha chega pelo contexto do arranque, para o grafo de módulos não ganhar um ciclo.
 */

let els;
let onChange = () => {};
let renderDownlinkRequests = () => {};
let renderTelemetryList = () => {};

export function initDetailFilters(context) {
    els = context.els;
    onChange = context.onChange;
    renderDownlinkRequests = context.renderDownlinkRequests;
    renderTelemetryList = context.renderTelemetryList;
    activeRange = "";
    els.detailRangePresets?.addEventListener("click", (event) => {
        const button = event.target.closest("[data-detail-range]");
        if (button) applyDetailRange(button.dataset.detailRange);
    });
    syncDetailSearchPlaceholder();
}

/**
 * O texto da busca nomeia o painel que está à vista. A partir do `xl` os dois estão lado a
 * lado debaixo do mesmo campo, e aí só o texto geral serve.
 */
export function syncDetailSearchPlaceholder() {
    if (!els?.detailSearch) return;

    const sideBySide = globalThis.matchMedia?.("(min-width: 1200px)")?.matches;
    const onRequests = !sideBySide && els.downlinkColumn?.classList.contains("active");
    const onReadings = !sideBySide && els.telemetryColumn?.classList.contains("active");

    els.detailSearch.placeholder = onRequests
        ? "Procurar nos pedidos"
        : onReadings ? "Procurar nas leituras" : "Procurar";
}

/**
 * Os alcances prontos. O «Hoje» conta da meia-noite de cá e não de vinte e quatro horas
 * atrás, que é o que quem carrega no botão quer dizer.
 */
const DETAIL_RANGES = {
    today: {
        label: "Hoje",
        start: () => {
            const midnight = new Date();
            midnight.setHours(0, 0, 0, 0);
            return midnight;
        },
    },
    "7d": { label: "7 dias", start: () => daysAgo(7) },
    "30d": { label: "30 dias", start: () => daysAgo(30) },
};

/** Qual dos alcances está aplicado, para a pastilha e para o botão carregado. */
let activeRange = "";

function daysAgo(days) {
    return new Date(Date.now() - days * 86400000);
}

/** O valor de um `datetime-local`: hora local, sem fuso e sem segundos. */
function dateTimeLocal(date) {
    const pad = (value) => String(value).padStart(2, "0");
    const day = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    return `${day}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Mexer num filtro leva a lista ao princípio, e desfaz o que o «Carregar mais» juntou. */
function restartTelemetryPaging() {
    state.telemetryPage = 1;
    state.telemetryCumulative = false;
}

export function applyDetailRange(range) {
    const preset = DETAIL_RANGES[range];
    if (!preset) return;

    activeRange = range;
    state.detailFilters = {
        ...state.detailFilters,
        from: dateTimeLocal(preset.start()),
        to: "",
    };
    resetDetailFiltersDraft();
    restartTelemetryPaging();
    onChange();
}

const DETAIL_ITEM_TYPES = {
    "device.connected": () => "device.connected",
    "device.disconnected": () => "device.disconnected",
};

/** As gravidades que o filtro oferece, com a etiqueta da pastilha. */
export const DETAIL_SEVERITIES = {
    alarm: "Alarmes",
    alert: "Alertas",
};

export function allDetailItems() {
    const items = [];
    const recent = state.selectedDetail.recent || {};
    for (const row of recent.telemetry || []) {
        const payload = rowPayload(row);
        // O heartbeat é o envelope de manutenção de ligação e não uma leitura: a bateria,
        // os passos e o sinal que traz saem dele como eventos próprios.
        if (payload && !payload.debug && payload.type !== "heartbeat")
            items.push({ _source: "telemetry", raw: row, payload });
    }
    for (const row of recent.events || []) {
        const payload = rowPayload(row);
        if (!payload) continue;
        // Os acontecimentos de domínio levam a gravidade que o hub lhes deu; as ligações não.
        if (payload.severity)
            items.push({ _source: "event", raw: row, payload });
        if (
            payload.type === "device.connected" ||
            payload.type === "device.disconnected"
        )
            items.push({ _source: "connection", raw: row, payload });
    }
    // As ligações têm lista própria; as dos eventos são as antigas, de antes de a haver.
    for (const row of recent.connections || []) {
        const payload = rowPayload(row);
        if (payload) items.push({ _source: "connection", raw: row, payload });
    }
    for (const row of recent.commands || []) {
        const payload = rowPayload(row);
        if (payload) items.push({ _source: "command", raw: row, payload });
    }
    return items;
}

export function filterDetailItems(items) {
    const { from, to, type, severity, q } = state.detailFilters;
    // A pesquisa corre sobre o que está carregado, que é a janela escolhida nas datas, e
    // compara com o que a pessoa vê na linha: o tipo e o valor formatado.
    const needle = String(q || "").trim().toLowerCase();
    return items.filter((item) => {
        if (type !== "all" && type !== "") {
            const itemType = detailItemType(item);
            if (itemType !== type) return false;
        }
        if (severity && severity !== "all" && item.payload?.severity !== severity) {
            return false;
        }
        if (from || to) {
            const time = itemTime(item);
            if (!time) return false;
            if (from && time < new Date(from).getTime()) return false;
            if (to && time > new Date(to).getTime()) return false;
        }
        if (needle !== "" && !detailItemHaystack(item).includes(needle)) {
            return false;
        }
        return true;
    });
}

/**
 * O que a linha mostra, em minúsculas: a etiqueta do catálogo e o valor formatado. A chave em
 * inglês fica ao lado, porque quem opera o hub procura por ela.
 */
function detailItemHaystack(item) {
    const itemType = detailItemType(item);
    const content = uplinkCardContent(itemType, item.payload?.data || {});
    return `${itemType} ${telemetryFilterLabel(itemType)} ${content?.value ?? ""}`.toLowerCase();
}

function detailFilterTypesFromItems(items) {
    return Array.from(
        new Set(
            items
                .map((item) => detailItemType(item))
                .filter((type) => type && type !== "outros"),
        ),
    ).sort((left, right) =>
        String(telemetryFilterLabel(left)).localeCompare(
            String(telemetryFilterLabel(right)),
            "pt-PT",
        ),
    );
}

function detailItemType(item) {
    const p = item.payload;
    if (item._source === "command" && p.feature) return p.feature;
    const mapped = DETAIL_ITEM_TYPES[p.type];
    if (mapped) return mapped(p);
    if (p.nativeType) return p.nativeType;
    if (p.type && p.type !== "telemetry") return p.type;
    return "outros";
}

function itemTime(item) {
    const p = item.payload;
    return Date.parse(p.occurredAt || p.recordedAt || p.requestedAt || "");
}

export function populateDetailFilterTypes() {
    const select = els.detailFilterType;
    const currentValue = state.detailFiltersDraft?.type || state.detailFilters.type;
    const observedTypes = detailFilterTypesFromItems(
        allDetailItems().filter((item) => item._source !== "command"),
    );
    const signature = observedTypes.join("|");

    // Refeito e não acrescentado, para não ficarem os tipos do aparelho anterior; quem está
    // escolhido volta logo abaixo.
    if (select.dataset.detailFilterTypesSignature !== signature) {
        select.innerHTML = [
            "<option value=\"all\">Todos os tipos</option>",
            ...observedTypes.map(filterTypeOption),
        ].join("");
        select.dataset.detailFilterTypesSignature = signature;
    }

    // Um tipo escolhido que ainda não apareceu na janela carregada continua a oferecer-se:
    // senão o selector saltava para «Todos» e o filtro aplicado deixava de se ver.
    if (currentValue && currentValue !== "all") {
        if (!observedTypes.includes(currentValue)) {
            select.insertAdjacentHTML("beforeend", filterTypeOption(currentValue));
        }
        select.value = currentValue;
        return;
    }

    select.value = "all";
}

function filterTypeOption(type) {
    return html`<option value="${type}">${telemetryFilterLabel(type)}</option>`;
}

function telemetryFilterLabel(type) {
    return capabilityLabel(type) || type;
}

/** O rascunho manda mesmo vazio, daí o `??` e não o `||`: um campo apagado é uma escolha. */
export function syncDetailFilterControls() {
    els.detailFilterFrom.value = state.detailFiltersDraft?.from ?? state.detailFilters.from;
    els.detailFilterTo.value = state.detailFiltersDraft?.to ?? state.detailFilters.to;
    els.detailFilterType.value = state.detailFiltersDraft?.type ?? state.detailFilters.type;
    els.detailFilterSeverity.value = state.detailFiltersDraft?.severity ?? state.detailFilters.severity;
    syncDetailRangeButtons();
    renderDetailActiveFilters();
}

function syncDetailRangeButtons() {
    const buttons = els.detailRangePresets?.querySelectorAll("[data-detail-range]") || [];
    for (const button of buttons) {
        const chosen = button.dataset.detailRange === activeRange;
        button.classList.toggle("btn-primary", chosen);
        button.classList.toggle("btn-outline-secondary", !chosen);
        button.setAttribute("aria-pressed", chosen ? "true" : "false");
    }
}

export function applyDetailFilters() {
    activeRange = "";
    state.detailFilters = {
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        severity: els.detailFilterSeverity.value,
        q: state.detailFilters.q,
    };
    resetDetailFiltersDraft();
    restartTelemetryPaging();
    onChange();
}

/** O tipo e a gravidade aplicam-se ao escolher, como os alcances: não há nada a meio de escrever. */
export function applyDetailType() {
    state.detailFilters = {
        ...state.detailFilters,
        type: els.detailFilterType.value,
        severity: els.detailFilterSeverity.value,
    };
    resetDetailFiltersDraft();
    restartTelemetryPaging();
    onChange();
}

export function clearDetailFilters() {
    activeRange = "";
    state.detailFilters = { from: "", to: "", type: "all", severity: "all", q: "" };
    resetDetailFiltersDraft();
    restartTelemetryPaging();
    if (els.detailSearch) els.detailSearch.value = "";
    onChange();
}

/**
 * A pesquisa não espera pelo "Aplicar". Os selects de data e tipo têm botão porque uma
 * data a meio de ser escrita não é uma data; um texto a meio já é um prefixo útil.
 */
let detailSearchTimer = null;

// O render é pesado: espera-se que a escrita pare.
export function applyDetailSearch() {
    clearTimeout(detailSearchTimer);
    detailSearchTimer = setTimeout(applyDetailSearchNow, 150);
}

function applyDetailSearchNow() {
    state.detailFilters = {
        ...state.detailFilters,
        q: els.detailSearch.value,
    };
    updateDetailFiltersDraft({ q: els.detailSearch.value });
    restartTelemetryPaging();
    onChange();
}

export function removeDetailFilter(key) {
    if (key === "from" || key === "to") activeRange = "";
    const cleared = key === "type" || key === "severity" ? "all" : "";
    state.detailFilters = { ...state.detailFilters, [key]: cleared };
    resetDetailFiltersDraft();
    restartTelemetryPaging();
    if (key === "q" && els.detailSearch) els.detailSearch.value = "";
    onChange();
}

/** O que cada pastilha diz; a do tipo leva a mesma etiqueta do select que a escolheu. */
export function detailFilterChipLabels({ from, to, type, severity, q }) {
    const labels = [];
    if (from || to) {
        const range = DETAIL_RANGES[activeRange];
        labels.push({
            key: range || (from && to) ? "range" : from ? "from" : "to",
            label: range
                ? range.label
                : `${from ? when(from) : "início"} → ${to ? when(to) : "agora"}`,
        });
    }
    if (type && type !== "all") {
        labels.push({ key: "type", label: telemetryFilterLabel(type) });
    }
    if (DETAIL_SEVERITIES[severity]) {
        labels.push({ key: "severity", label: DETAIL_SEVERITIES[severity] });
    }
    if (String(q || "").trim() !== "") {
        labels.push({ key: "q", label: `"${q.trim()}"` });
    }

    return labels;
}

/** As pastilhas do que está aplicado, na linha abaixo da pesquisa. */
function renderDetailActiveFilters() {
    const labels = detailFilterChipLabels(state.detailFilters);

    renderInto(els.detailActiveFilters, filterChips(labels, "removeDetailFilter"));
    els.detailFilterCount.textContent = labels.length ? String(labels.length) : "";
    els.clearDetailFiltersBtn.classList.toggle("d-none", labels.length === 0);
    // Sem filtros aplicados a linha inteira sai, para não sobrar espaço sem conteúdo.
    els.detailActiveFiltersRow?.classList.toggle("d-none", labels.length === 0);
}

export function updateDetailFilterDraft() {
    updateDetailFiltersDraft({
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        severity: els.detailFilterSeverity.value,
        q: state.detailFilters.q,
    });
}

/**
 * Os dois painéis paginam no cliente sobre o que os filtros deixaram passar, por isso a conta
 * das páginas faz-se aqui e não vem da API.
 */
function paginateDetailPanel(event, { belongsToPanel, pageSize, page, actionPrefix, setPage, render }) {
    if (!state.selectedDetail) return;

    const rows = filterDetailItems(allDetailItems())
        .filter(belongsToPanel)
        .map((item) => item.raw);
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    // O «Carregar mais» pede a mesma página seguinte das setas, e manda juntá-la ao que já
    // está na lista em vez de a substituir.
    const more = event.target?.closest?.(`[data-action="${actionPrefix}More"]`);
    const nextPage = more
        ? Math.min(totalPages, page + 1)
        : resolvePaginationPage(event, { page, total_pages: totalPages }, actionPrefix);
    if (nextPage === null) return;

    setPage(nextPage, totalPages);
    state[`${actionPrefix}Cumulative`] = !!more;
    render(rows);
}

export function handleDownlinkPagerClick(event) {
    paginateDetailPanel(event, {
        belongsToPanel: (item) => item._source === "command",
        pageSize: state.downlinkPageSize,
        page: state.downlinkPage,
        actionPrefix: "downlink",
        setPage: setDownlinkPage,
        render: renderDownlinkRequests,
    });
}

export function handleTelemetryPagerClick(event) {
    paginateDetailPanel(event, {
        belongsToPanel: (item) => ["telemetry", "event"].includes(item._source),
        pageSize: state.telemetryPageSize,
        page: state.telemetryPage,
        actionPrefix: "telemetry",
        setPage: setTelemetryPage,
        render: renderTelemetryList,
    });
}
