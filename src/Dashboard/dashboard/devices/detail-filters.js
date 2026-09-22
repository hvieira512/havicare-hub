import { capabilityLabel } from "../capability-catalog.js";
import { rowPayload, when } from "../format.js";
import { html } from "../html.js";
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
 * Os filtros do histórico de um dispositivo -- a janela de datas, o tipo e a pesquisa -- e os
 * paginadores dos dois painéis que eles reduzem.
 *
 * Saíram do `detail.js` porque não desenham o ecrã: reduzem uma lista, dizem que pastilhas
 * mostrar e escolhem que página dela se vê. Tudo o que volta a desenhar entra pelo contexto
 * do arranque -- o `onChange` e os dois renderizadores dos painéis -- e não por um import de
 * volta: o grafo de módulos da dashboard não tem ciclos e não é aqui que ganha o primeiro.
 *
 * Não confundir com o `devices/list-filters.js`, que filtra a *listagem* de dispositivos.
 * Estes filtram o que um dispositivo já reportou.
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
}

const DETAIL_ITEM_TYPES = {
    "device.connected": () => "device.connected",
    "device.disconnected": () => "device.disconnected",
};

/** O que chegar em `events` e não estiver aqui é descartado em silêncio. */
const ALARM_EVENT_TYPES = new Set([
    "alarm",
    "help_call",
    "reset",
    "fall",
    "vitals_alarm",
    "presence_event",
    "medication_intake",
    "device_fault",
]);

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
        if (ALARM_EVENT_TYPES.has(payload.type))
            items.push({ _source: "event", raw: row, payload });
        if (
            payload.type === "device.connected" ||
            payload.type === "device.disconnected"
        )
            items.push({ _source: "connection", raw: row, payload });
    }
    for (const row of recent.commands || []) {
        const payload = rowPayload(row);
        if (payload) items.push({ _source: "command", raw: row, payload });
    }
    return items;
}

export function filterDetailItems(items) {
    const { from, to, type, q } = state.detailFilters;
    // A pesquisa corre sobre o que está carregado, que é a janela escolhida nas datas, e
    // compara com o que a pessoa vê na linha: o tipo e o valor formatado.
    const needle = String(q || "").trim().toLowerCase();
    return items.filter((item) => {
        if (type !== "all" && type !== "") {
            const itemType = detailItemType(item);
            if (itemType !== type) return false;
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
 * O que a linha mostra, em minúsculas: o tipo e o valor formatado.
 *
 * A etiqueta é a mesma que a linha e o select do tipo apresentam -- português, vinda do
 * catálogo. A chave em inglês fica ao lado, porque quem opera o hub procura por ela.
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
    const hasCurrentValue = Array.from(select.options || []).some(
        (option) => option.value === currentValue,
    );

    if (select.dataset.detailFilterTypesSignature !== signature) {
        const existingTypes = new Set(
            Array.from(select.options || [])
                .map((option) => option.value)
                .filter((value) => value && value !== "all"),
        );
        const missingTypes = observedTypes.filter((type) => !existingTypes.has(type));

        if (select.dataset.detailFilterTypesSignature) {
            for (const type of missingTypes) {
                select.insertAdjacentHTML("beforeend", filterTypeOption(type));
            }
        } else {
            select.innerHTML = [
                "<option value=\"all\">Todos</option>",
                ...observedTypes.map(filterTypeOption),
            ].join("");
        }

        select.dataset.detailFilterTypesSignature = signature;
    }

    if (currentValue && currentValue !== "all") {
        if (!hasCurrentValue) {
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

/**
 * O rascunho manda mesmo quando está vazio: um campo apagado é uma escolha, e com `||` caía
 * no valor aplicado. Quem limpasse uma data via-a voltar à primeira mensagem do stream, e o
 * «Aplicar» seguinte lia o campo já repovoado e reaplicava-a.
 */
export function syncDetailFilterControls() {
    els.detailFilterFrom.value = state.detailFiltersDraft?.from ?? state.detailFilters.from;
    els.detailFilterTo.value = state.detailFiltersDraft?.to ?? state.detailFilters.to;
    els.detailFilterType.value = state.detailFiltersDraft?.type ?? state.detailFilters.type;
    renderDetailActiveFilters();
}

export function applyDetailFilters() {
    state.detailFilters = {
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        q: state.detailFilters.q,
    };
    resetDetailFiltersDraft();
    state.telemetryPage = 1;
    onChange();
}

export function clearDetailFilters() {
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
    resetDetailFiltersDraft();
    state.telemetryPage = 1;
    if (els.detailSearch) els.detailSearch.value = "";
    onChange();
}

/**
 * A pesquisa não espera pelo "Aplicar". Os selects de data e tipo têm botão porque uma
 * data a meio de ser escrita não é uma data; um texto a meio já é um prefixo útil.
 */
let detailSearchTimer = null;

// A pesquisa filtra a cada tecla, mas o render é pesado -- lista, cartões e tooltips; espera-se
// que a escrita pare, como já faz a lista de dispositivos.
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
    state.telemetryPage = 1;
    onChange();
}

export function removeDetailFilter(key) {
    const cleared = key === "type" ? "all" : "";
    state.detailFilters = { ...state.detailFilters, [key]: cleared };
    resetDetailFiltersDraft();
    state.telemetryPage = 1;
    if (key === "q" && els.detailSearch) els.detailSearch.value = "";
    onChange();
}

/**
 * O que cada pastilha diz. A do tipo leva a mesma etiqueta do select que a escolheu: aplicar
 * um filtro em português e vê-lo voltar em inglês era a mesma coisa dita de duas maneiras.
 */
export function detailFilterChipLabels({ from, to, type, q }) {
    const labels = [];
    if (from || to) {
        labels.push({
            key: from && to ? "range" : from ? "from" : "to",
            label: `${from ? when(from) : "início"} → ${to ? when(to) : "agora"}`,
        });
    }
    if (type && type !== "all") {
        labels.push({ key: "type", label: telemetryFilterLabel(type) });
    }
    if (String(q || "").trim() !== "") {
        labels.push({ key: "q", label: `"${q.trim()}"` });
    }

    return labels;
}

/** As pastilhas do que está aplicado, na linha abaixo da pesquisa. */
function renderDetailActiveFilters() {
    const labels = detailFilterChipLabels(state.detailFilters);

    els.detailActiveFilters.innerHTML = filterChips(labels, "removeDetailFilter");
    els.detailFilterCount.textContent = labels.length ? String(labels.length) : "";
    els.detailFilterCount.classList.toggle("d-none", labels.length === 0);
    els.clearDetailFiltersBtn.classList.toggle("d-none", labels.length === 0);
    // Sem filtros aplicados a linha inteira sai, para não sobrar espaço sem conteúdo.
    els.detailActiveFiltersRow?.classList.toggle("d-none", labels.length === 0);
}

export function updateDetailFilterDraft() {
    updateDetailFiltersDraft({
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        q: state.detailFilters.q,
    });
}

/**
 * Um clique num paginador de um painel do detalhe.
 *
 * Os dois painéis paginam do lado do cliente sobre o que os filtros deixaram passar, e por
 * isso a conta das páginas é feita aqui e não vem da API. O que os distingue é só o que cada
 * um deixa passar, o tamanho da página e onde escreve o resultado.
 */
function paginateDetailPanel(event, { belongsToPanel, pageSize, page, actionPrefix, setPage, render }) {
    if (!state.selectedDetail) return;

    const rows = filterDetailItems(allDetailItems())
        .filter(belongsToPanel)
        .map((item) => item.raw);
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    const nextPage = resolvePaginationPage(
        event,
        { page, total_pages: totalPages },
        actionPrefix,
        `${actionPrefix}PageGo`,
    );
    if (nextPage === null) return;

    setPage(nextPage, totalPages);
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
