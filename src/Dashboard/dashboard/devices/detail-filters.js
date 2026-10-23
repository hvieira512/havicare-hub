import { capabilityLabel } from "../capability-catalog.js";
import { UNTRANSLATED } from "../i18n.js";
import { rowPayload, when } from "../format.js";
import { html } from "../html.js";
import { els, renderInto } from "../dom.js";
import {
    blankDetailFilters,
    resetActivityPaging,
    resetDetailFiltersDraft,
    setDownlinkPage,
    setTelemetryPage,
    state,
    updateDetailFiltersDraft,
} from "../state.js";
import { resolvePaginationPage } from "../pagination.js";
import { uplinkCardContent } from "../components/cards/telemetry.js";
import { filterChips } from "../components/chips.js";

import { renderDownlinkRequests, renderSelection, renderTelemetryList } from "./detail.js";

/** Filtros e paginadores do histórico de um dispositivo. */

export function initDetailFilters() {
    activeRange = "";
    els.detailRangePresets?.addEventListener("click", (event) => {
        const button = event.target.closest("[data-detail-range]");
        if (button) applyDetailRange(button.dataset.detailRange);
    });
    syncDetailSearchPlaceholder();
}

/** A partir do `xl` as leituras e os pedidos estão lado a lado. */
export const SIDE_BY_SIDE_QUERY = "(min-width: 1200px)";

/** A busca nomeia o painel à vista; com os dois à vista, serve o texto geral. */
export function syncDetailSearchPlaceholder() {
    if (!els?.detailSearch) return;

    const sideBySide = globalThis.matchMedia?.(SIDE_BY_SIDE_QUERY)?.matches;
    const onRequests = !sideBySide && els.downlinkColumn?.classList.contains("active");
    const onReadings = !sideBySide && els.telemetryColumn?.classList.contains("active");

    els.detailSearch.placeholder = onRequests
        ? "Procurar nos pedidos"
        : onReadings ? "Procurar nas leituras" : "Procurar";
}

/** O «Hoje» conta da meia-noite de cá, e não de vinte e quatro horas atrás. */
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

export function applyDetailRange(range) {
    const preset = DETAIL_RANGES[range];
    if (!preset) return;

    activeRange = range;
    setDetailFilters({ from: dateTimeLocal(preset.start()), to: "" });
}

/** Aplica uma mudança aos filtros: o rascunho volta ao aplicado e as listas à primeira página. */
function setDetailFilters(patch) {
    state.detailFilters = { ...state.detailFilters, ...patch };
    resetDetailFiltersDraft();
    resetActivityPaging();
    renderSelection();
}

const DETAIL_ITEM_TYPES = {
    "device.connected": () => "device.connected",
    "device.disconnected": () => "device.disconnected",
};

export const DETAIL_SEVERITIES = {
    alarm: "Alarmes",
    alert: "Alertas",
};

export function allDetailItems() {
    const items = [];
    const recent = state.selectedDetail.recent || {};
    for (const row of recent.telemetry || []) {
        const payload = rowPayload(row);
        if (payload && !payload.debug)
            items.push({ _source: "telemetry", raw: row, payload });
    }
    for (const row of recent.events || []) {
        const payload = rowPayload(row);
        if (payload?.severity) items.push({ _source: "event", raw: row, payload });
    }
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
    // A pesquisa corre sobre o que está carregado e compara com o que a linha mostra.
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

/** O que a linha mostra, em minúsculas, e a chave em inglês, porque quem opera procura por ela. */
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
                .filter(Boolean),
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
    // Um tipo sem nome na dashboard não se mostra pelo código do fabricante.
    return p.type && p.type !== "telemetry" && capabilityLabel(p.type) !== UNTRANSLATED ? p.type : "outros";
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

    // Refeito e não acrescentado, para não ficarem os tipos do aparelho anterior.
    if (select.dataset.detailFilterTypesSignature !== signature) {
        select.innerHTML = [
            "<option value=\"all\">Todos os tipos</option>",
            ...observedTypes.map(filterTypeOption),
        ].join("");
        select.dataset.detailFilterTypesSignature = signature;
    }

    // Um tipo escolhido que não está na janela carregada continua a oferecer-se.
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
    return type === "outros" ? "Outros" : capabilityLabel(type);
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
    setDetailFilters({
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        severity: els.detailFilterSeverity.value,
    });
}

/** O tipo e a gravidade aplicam-se ao escolher, como os alcances. */
export function applyDetailType() {
    setDetailFilters({
        type: els.detailFilterType.value,
        severity: els.detailFilterSeverity.value,
    });
}

export function clearDetailFilters() {
    activeRange = "";
    if (els.detailSearch) els.detailSearch.value = "";
    setDetailFilters(blankDetailFilters());
}

/** A pesquisa não espera pelo «Aplicar»: um texto a meio já é um prefixo útil. */
let detailSearchTimer = null;

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
    resetActivityPaging();
    renderSelection();
}

export function removeDetailFilter(key) {
    if (key === "from" || key === "to") activeRange = "";
    if (key === "q" && els.detailSearch) els.detailSearch.value = "";
    setDetailFilters({ [key]: blankDetailFilters()[key] });
}

/** A pastilha do tipo leva a mesma etiqueta do select que a escolheu. */
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

function renderDetailActiveFilters() {
    const labels = detailFilterChipLabels(state.detailFilters);

    renderInto(els.detailActiveFilters, filterChips(labels, "removeDetailFilter"));
    els.detailFilterCount.textContent = labels.length ? String(labels.length) : "";
    els.clearDetailFiltersBtn.classList.toggle("d-none", labels.length === 0);
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

/** Os dois painéis paginam no cliente o que os filtros deixaram passar. */
function paginateDetailPanel(event, { belongsToPanel, pageSize, page, actionPrefix, setPage, render }) {
    if (!state.selectedDetail) return;

    const rows = filterDetailItems(allDetailItems())
        .filter(belongsToPanel)
        .map((item) => item.raw);
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    // O «Carregar mais» pede a página seguinte e junta-a à lista em vez de a substituir.
    const more = event.target?.closest?.(`[data-action="${actionPrefix}More"]`);
    const nextPage = more
        ? Math.min(totalPages, page + 1)
        : resolvePaginationPage(event, { page, totalPages }, actionPrefix);
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
