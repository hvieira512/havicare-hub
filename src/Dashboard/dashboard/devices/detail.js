import {
    requestFeature as apiRequestFeature,
} from "../api/index.js";
import {
    resetDetailFiltersDraft,
    setDownlinkPage,
    setTelemetryPage,
    state,
    updateDetailFiltersDraft,
} from "../state.js";
import { deviceTypeLabel, normalizeDeviceType } from "../domain.js";
import {
    commandLabel,
    eventTime,
    rowPayload,
    when,
    whenShort,
} from "../format.js";
import { html, raw } from "../html.js";
import { capabilityLabel } from "../capability-catalog.js";
import { apiError, toast } from "../dialogs.js";
import { filterChips } from "../widgets.js";
import { deviceLicenseBlock } from "../components/device-license.js";
import { onlineBadge } from "../components/state-badge.js";
import {
    cardTone,
    statusBadge,
    uplinkCardContent,
} from "../telemetry-cards.js";
import { telemetryCard } from "../card-shell.js";
import { renderRequestCardShell, requestCardContent } from "../request-card.js";
import { fallSummaryCard, helpCallSummaryCard } from "./event-summary-cards.js";
import { activityTable } from "./activity-table.js";
import { protocolHelpCallPressModes } from "./config/protocol-catalog.js";
import { renderPagination } from "../pagination.js";
import { SELECTED_DEVICE_STORAGE_KEY, clearStorageKey, saveTextStorage } from "../storage.js";
import { disposeTooltips, refreshTooltips } from "../tooltips.js";
import { gatewaySignalRows } from "./gateway-signal.js";

const DETAIL_ITEM_TYPES = {
    "device.connected": () => "device.connected",
    "device.disconnected": () => "device.disconnected",
};

const NCS_EVENT_CARD_TYPES = ["help_call", "reset"];

/** O que chegar em `events` e não estiver aqui é descartado em silêncio. */
const ALARM_EVENT_TYPES = new Set([
    "alarm",
    "help_call",
    "reset",
    "fall",
    "vitals_alarm",
    "presence_event",
]);

let els;

function initDeviceDetailView(context) {
    els = context.els;
}

function renderSelection() {
    els.deviceSelectionEmptyState.classList.toggle(
        "d-none",
        !!state.selectedDetail,
    );
    els.selectedDevicePanel.classList.toggle("d-none", !state.selectedDetail);
    els.deviceDetail.classList.toggle("d-none", !state.selectedDetail);
    // Sem dispositivo escolhido a coluna da atividade não diz nada que a da esquerda não
    // diga, e essa tem o botão: desaparece, e a da escolha ocupa a largura toda.
    els.detailColumn.classList.toggle("d-none", !state.selectedDetail);
    els.deviceColumn.classList.toggle("col-lg-4", !!state.selectedDetail);
    // Sem dispositivo escolhido, o cartão dos pedidos não tem mosaico nenhum para mostrar.
    els.requestCardsCard?.classList.toggle("d-none", !state.selectedDetail);
    if (!state.selectedDetail) {
        els.requestGrid.innerHTML = "";
        els.ncsEventGrid.innerHTML = "";
        els.ncsEventSection.classList.add("d-none");
        return;
    }

    if (!state.detailFiltersDraft || typeof state.detailFiltersDraft !== "object") {
        resetDetailFiltersDraft();
    }

    const device = state.selectedDetail.device;
    const deviceModel = state.selectedDetail.model;
    renderSelectedDeviceSummary(
        device,
        deviceModel,
        state.selectedDetail.linkedDevices || [],
    );

    populateDetailFilterTypes();
    syncDetailFilterControls();

    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const deviceType = normalizeDeviceType(deviceModel?.deviceType || "watch");
    const alarmEvents = filtered
        .filter((item) => item._source === "event")
        .map((item) => item.raw);
    const telemetry = filtered
        .filter((item) => item._source === "telemetry")
        .map((item) => item.raw);
    const commands = filtered
        .filter((item) => item._source === "command")
        .map((item) => item.raw);
    const connectionEvents = filtered
        .filter((item) => item._source === "connection")
        .map((item) => item.raw);

    renderTelemetryList([...telemetry, ...alarmEvents]);
    renderRequestCards(
        telemetryRequestCards(
            state.selectedDetail?.capabilities?.telemetry || {},
        ),
        telemetry,
        alarmEvents,
        commands,
    );
    if (deviceType === "ncs") {
        renderNcsEventCards(alarmEvents);
    } else {
        els.ncsEventGrid.innerHTML = "";
        els.ncsEventSection.classList.add("d-none");
    }
    renderDownlinkRequests(commands);
    renderConnectionTimeline(connectionEvents);
}

const TELEMETRY_REQUEST_GROUPS = [
    {
        key: "telemetry",
        label: "Telemetria",
    },
    {
        key: "system",
        label: "Informação do sistema",
    },
];

const TELEMETRY_REQUEST_SYSTEM_FEATURES = new Set([
    "firmware_version",
    "device_status",
]);

/**
 * Capacidades sem mosaico próprio. O resumo diz o estado agora, e os agregados do radar são
 * médias do último minuto -- continuam na lista de eventos, onde a hora lhes dá sentido.
 */
const TELEMETRY_REQUEST_HIDDEN_FEATURES = new Set([
    "diaper_moisture_level",
    "position_minute_stats",
    "vitals_minute_stats",
]);

function telemetryRequestCards(telemetryCapabilities = {}) {
    const cards = Object.entries(telemetryCapabilities || {})
        .filter(([, entry]) => entry?.supported)
        .filter(([feature]) => !TELEMETRY_REQUEST_HIDDEN_FEATURES.has(feature))
        .map(([feature, entry]) => ({
            id: feature,
            feature,
            requestable: !!entry?.requestable,
            group: TELEMETRY_REQUEST_SYSTEM_FEATURES.has(feature)
                ? "system"
                : "telemetry",
        }))
        .sort((a, b) => {
            if (a.group !== b.group) {
                return a.group === "telemetry" ? -1 : 1;
            }

            return String(capabilityLabel(a.feature || "")).localeCompare(
                String(capabilityLabel(b.feature || "")),
                "pt-PT",
            );
        });

    return TELEMETRY_REQUEST_GROUPS
        .map((group) => ({
            ...group,
            cards: cards.filter((card) => card.group === group.key),
        }))
        .filter((group) => group.cards.length);
}

function renderSelectedDeviceSummary(device, deviceModel, linkedDevices = []) {
    const supplier = String(deviceModel?.supplier || "");
    const model = String(deviceModel?.internalModel || "");
    const image = String(deviceModel?.image || "");
    const typeLabel = deviceTypeLabel(
        normalizeDeviceType(deviceModel?.deviceType || "watch"),
    );
    const facts = [
        {
            label: "Licença",
            html: deviceLicenseBlock(device, {
                valueClass: "d-block",
                noteClass: "d-block small text-body-secondary",
            }),
        },
        {
            label: "Última ligação",
            value: when(device.lastSeenAt) || "Sem registo",
        },
    ];

    if (device.simNumber) {
        facts.push({ label: "SIM", value: String(device.simNumber) });
    }
    if (linkedDevices.length) {
        // Uma lista de MACs não diz se esses gateways ouvem o dispositivo agora, que é a
        // parte útil.
        facts.push({
            label: "Dispositivos ligados",
            html: gatewaySignalRows(linkedDevices),
            wide: true,
        });
    }

    // A imagem do modelo é estável, mas o render corre a cada mensagem do stream: recriar o
    // `<img>` fazia o browser recarregá-lo e piscar. Só se refaz quando muda.
    const previewKey = image || "__none__";
    if (els.selectedDevicePreview.dataset.previewKey !== previewKey) {
        els.selectedDevicePreview.dataset.previewKey = previewKey;
        els.selectedDevicePreview.innerHTML = image
            ? html`<img src="${image}" class="object-fit-contain" alt="${model || device.imei}" style="max-width:56px;max-height:56px;">`
            : "<i class=\"fa-solid fa-microchip fa-xl text-secondary\"></i>";
    }
    els.selectedDeviceTitle.textContent = device.imei;
    // O estado é a primeira coisa que se pergunta sobre um dispositivo, e por isso vem
    // antes do identificador.
    els.selectedDeviceBadge.innerHTML = onlineBadge(device.online);
    els.selectedDeviceMeta.textContent = `${typeLabel} · ${supplier || "Sem fornecedor"} · ${model || "Sem modelo interno"}`;
    disposeTooltips(els.selectedDeviceFacts);
    els.selectedDeviceFacts.innerHTML = facts
        .map(
            (item) => html`
        <div class="${item.wide ? "col-12" : "col-6"}">
            <dt class="mb-1">${item.label}</dt>
            <dd class="text-break mb-0">${item.html ? raw(item.html) : item.value}</dd>
        </div>
    `,
        )
        .join("");
    refreshTooltips(els.selectedDeviceFacts);
}

function allDetailItems() {
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

function filterDetailItems(items) {
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

function populateDetailFilterTypes() {
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

function syncDetailFilterControls() {
    els.detailFilterFrom.value = state.detailFiltersDraft?.from || state.detailFilters.from;
    els.detailFilterTo.value = state.detailFiltersDraft?.to || state.detailFilters.to;
    els.detailFilterType.value = state.detailFiltersDraft?.type || state.detailFilters.type;
    renderDetailActiveFilters();
}

function applyDetailFilters() {
    state.detailFilters = {
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        q: state.detailFilters.q,
    };
    resetDetailFiltersDraft();
    state.telemetryPage = 1;
    renderSelection();
}

function clearDetailFilters() {
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
    resetDetailFiltersDraft();
    state.telemetryPage = 1;
    if (els.detailSearch) els.detailSearch.value = "";
    renderSelection();
}

/**
 * A pesquisa não espera pelo "Aplicar". Os selects de data e tipo têm botão porque uma
 * data a meio de ser escrita não é uma data; um texto a meio já é um prefixo útil.
 */
let detailSearchTimer = null;

// A pesquisa filtra a cada tecla, mas o render é pesado -- lista, cartões e tooltips; espera-se
// que a escrita pare, como já faz a lista de dispositivos.
function applyDetailSearch() {
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
    renderSelection();
}

function removeDetailFilter(key) {
    const cleared = key === "type" ? "all" : "";
    state.detailFilters = { ...state.detailFilters, [key]: cleared };
    resetDetailFiltersDraft();
    state.telemetryPage = 1;
    if (key === "q" && els.detailSearch) els.detailSearch.value = "";
    renderSelection();
}

/**
 * O que cada pastilha diz. A do tipo leva a mesma etiqueta do select que a escolheu: aplicar
 * um filtro em português e vê-lo voltar em inglês era a mesma coisa dita de duas maneiras.
 */
function detailFilterChipLabels({ from, to, type, q }) {
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

function updateDetailFilterDraft() {
    updateDetailFiltersDraft({
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        q: state.detailFilters.q,
    });
}

function renderTelemetryList(telemetryRows) {
    const telemetry = telemetryRows
        .map(rowPayload)
        .filter((payload) => payload && !payload.debug)
        .sort((a, b) => eventTime(b) - eventTime(a));
    const totalPages = Math.max(
        1,
        Math.ceil(telemetry.length / state.telemetryPageSize),
    );
    setTelemetryPage(state.telemetryPage, totalPages);

    const start = (state.telemetryPage - 1) * state.telemetryPageSize;
    const pageRows = telemetry.slice(start, start + state.telemetryPageSize);

    // Na pastilha do contador cabe o número e mais nada: o título já diz de quê.
    els.telemetryCount.textContent = telemetry.length ? String(telemetry.length) : "";
    activityTable(
        els.telemetryList,
        pageRows.map(telemetryActivityRow),
        "Ainda não há eventos recebidos.",
        // O prefixo é por lista: as duas desenham-se ao mesmo tempo no mesmo documento, e
        // com o mesmo `activityRowDetail0` em cada uma ficavam dois elementos com o mesmo id.
        "telemetryRowDetail",
        state.telemetryPage,
    );
    renderClientPager("telemetry", telemetry.length, totalPages);
}

/**
 * Os dois painéis paginam no cliente, mas os controlos são os mesmos da listagem servida
 * pela API: saem do mesmo `renderPagination`, com o resumo curto que estas colunas levam.
 */
function renderClientPager(prefix, totalRows, totalPages) {
    const root = els[`${prefix}Pager`];
    const summaryEl = els[`${prefix}PagerSummary`];
    const controlsEl = els[`${prefix}PagerControls`];
    // O resumo é opcional: nestes dois painéis o total já está na pastilha do título.
    if (!root || !controlsEl) return;

    renderPagination({
        pagination: {
            total: totalRows,
            total_pages: totalPages,
            page: state[`${prefix}Page`],
            limit: state[`${prefix}PageSize`],
        },
        rootEl: root,
        summaryEl,
        controlsEl,
        actionPrefix: prefix,
        goAction: `${prefix}PageGo`,
        summary: (start, end, total) => `${start}–${end} de ${total}`,
    });
}

// A tabela genérica de atividade (linhas, gaveta e paginação de rolagem) vive no seu próprio
// módulo -- a telemetria e os pedidos usam-na igual, e não tem nada do detalhe do dispositivo.

function telemetryActivityRow(payload) {
    const type = payload?.type || "telemetry";
    const data =
        payload?.data && typeof payload.data === "object" ? payload.data : {};
    const card = uplinkCardContent(type, data);
    // Os detalhes são os que cada renderizador declara, e não todos os campos do payload.
    const detail = card.details || "";
    // O `detailsTitle` existe quando a linha visível é um resumo: a presença mostra as
    // posturas e guarda para aqui as coordenadas e as pessoas que não couberam.
    const at = payload.occurredAt || payload.recordedAt;

    const detailText = card.detailsTitle ||
        detail.replace(/<br\s*\/?>/gi, " · ").replace(/<[^>]*>/g, "");

    return {
        icon: card.icon,
        tone: cardTone(type),
        name: capabilityLabel(type),
        value: html`${card.rowValue || card.value}`,
        detail,
        detailKind: card.detailsKind || "text",
        detailTitle: detailText,
        // O que a linha aberta mostra. Só há o que abrir se houver detalhes: uma frequência
        // cardíaca é o valor e mais nada, e uma seta a dizer que há mais era uma mentira.
        expanded: detailText,
        // O `seq` é monótono por dispositivo e lista. O IMEI vai na chave porque ele
        // recomeça em cada aparelho.
        key: `t:${state.selectedImei}:${payload?.seq ?? `${at}:${type}`}`,
        time: whenShort(at) || "hora desconhecida",
        timeTitle: when(at),
    };
}

function renderRequestCards(
    groups,
    telemetry = [],
    events = [],
    commands = [],
) {
    const totalCards = groups.reduce(
        (count, group) => count + group.cards.length,
        0,
    );
    // Do histórico de eventos e não de uma capacidade, para aparecer quando o dispositivo
    // pediu ajuda. Os modos de toque vêm declarados pelo protocolo.
    const helpCalls = helpCallSummaryCard(
        events,
        protocolHelpCallPressModes(state.selectedDetail?.model?.protocol || ""),
    );
    const falls = fallSummaryCard(events);

    disposeTooltips(els.requestGrid);

    // Com um grupo só, a faixa com o nome do grupo não separa nada.
    const cards = totalCards
        ? groups
                .map((group) =>
                    renderRequestCardGroup(
                        group,
                        telemetry,
                        groups.length > 1,
                        commands,
                    ),
                )
                .join("")
        : "";
    // Um W812 não aceita pedido nenhum, e o cartão vazio a dizê-lo ocupava a coluna com uma
    // grelha que nunca teria mosaicos. Sem nada para mostrar, a secção não existe.
    const grid = falls + helpCalls + cards;
    els.requestCardsCard?.classList.toggle("d-none", grid === "");
    els.requestGrid.innerHTML = grid;
    refreshTooltips(els.requestGrid);
}

function renderRequestCardGroup(
    group,
    telemetry = [],
    showLabel = true,
    commands = [],
) {
    const cards = group.cards
        .map((command) =>
            renderRequestCardShell(
                command,
                state.loadingCommands.has(
                    String(
                        command.id || command.feature || command.command || "",
                    ),
                ),
                telemetry,
                commands,
            ),
        )
        .join("");

    if (!showLabel) {
        return cards;
    }

    // O rótulo separa os grupos sem os meter dentro de outra caixa. A caixa com borda e
    // enchimento custava trinta e quatro pixéis de largura, e a grelha precisa de 464 numa
    // coluna que tem 481: com ela, os mosaicos caíam de dois por linha para um -- e só nos
    // aparelhos com mais do que um grupo, que são os únicos que a mostram.
    return html`
        <div class="telemetry-card-wide min-w-0">
        <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="section-label">${group.label || "Pedidos"}</div>
        <span class="count-chip fw-semibold px-2 rounded-pill tabular-nums">${group.cards.length}</span>
        </div>
        <div class="d-grid telemetry-card-grid gap-3">${raw(cards)}</div>
        </div>`;
}

function renderNcsEventCards(rows = []) {
    const cards = NCS_EVENT_CARD_TYPES.map((type) => {
        const latest = rows
            .map(rowPayload)
            .filter((payload) => payload && payload.type === type)
            .sort((a, b) => eventTime(b) - eventTime(a))[0];
        return latest ? { type, latest } : null;
    })
        .filter(Boolean)
        .sort((left, right) => eventTime(right.latest) - eventTime(left.latest));

    // Sem cartões a secção não aparece, e por isso não há estado vazio para desenhar.
    els.ncsEventSection.classList.toggle("d-none", cards.length === 0);
    els.ncsEventGrid.innerHTML = cards.map(renderNcsEventCard).join("");
}

/**
 * Mesma forma dos mosaicos de telemetria -- nome, valor, detalhe -- e não um corpo próprio
 * com duas linhas de texto corrido: o que aconteceu titula, o comando é o valor, e a hora
 * fica no detalhe.
 */
function renderNcsEventCard({ type, latest }) {
    const content = uplinkCardContent(type, latest.data || {});
    const timestamp = when(latest.occurredAt || latest.recordedAt) || "hora desconhecida";

    return telemetryCard({
        icon: content.icon,
        title: content.value,
        value: content.rowValue || "",
        details: html`Último evento: ${timestamp}`,
        tone: cardTone(type),
    });
}

function renderDownlinkRequests(commands) {
    els.downlinkRequestCount.textContent = commands.length ? String(commands.length) : "";

    // A maioria dos aparelhos -- radares, gateways, medidores de fralda -- não recebe pedido
    // nenhum, e metade do painel dizia permanentemente que não havia pedidos enquanto a lista
    // ao lado cortava "Alarme de sinais vit..." numa coluna de 34%. Sem pedidos, os eventos
    // ficam com a linha toda; com eles, volta a divisão a meio.
    const hasRequests = commands.length > 0;
    els.downlinkColumn?.classList.toggle("d-none", !hasRequests);
    els.telemetryColumn?.classList.toggle("col-xl-6", hasRequests);
    els.telemetryColumn?.classList.toggle("pe-xl-4", hasRequests);

    // Paginado como os eventos recebidos: sem páginas, os pedidos antigos ficam atrás de
    // um scroll interno que ninguém vê.
    const totalPages = Math.max(
        1,
        Math.ceil(commands.length / state.downlinkPageSize),
    );
    setDownlinkPage(state.downlinkPage, totalPages);

    const start = (state.downlinkPage - 1) * state.downlinkPageSize;
    const pageRows = commands.slice(start, start + state.downlinkPageSize);

    activityTable(
        els.downlinkRequests,
        pageRows.map(downlinkActivityRow),
        "Ainda não há pedidos ao dispositivo.",
        "downlinkRowDetail",
        state.downlinkPage,
    );

    renderClientPager("downlink", commands.length, totalPages);
}

function downlinkActivityRow(command) {
    const feature = String(command.feature || "");
    // A resposta cabe no `title` do estado, e o erro na segunda linha do nome.
    const replied = command.ackedAt
        ? `Resposta ${when(command.ackedAt)}`
        : command.sentAt
            ? `Enviado ${when(command.sentAt)}`
            : expectedReplies(command);
    const note = command.error || "";

    return {
        icon: requestCardContent(feature).icon,
        tone: cardTone(feature),
        name: commandLabel(command) || requestCardContent(feature).value || "Pedido",
        sub: note ? html`${note}` : "",
        subTitle: note,
        value: statusBadge(String(command.status || "unknown")),
        valueTitle: replied,
        // O erro corta-se na linha e a resposta só vivia num `title`. Abrindo, vêem-se os
        // dois por inteiro -- que num pedido falhado é justamente o que se quer ler.
        expanded: [note, replied].filter(Boolean).join(" · "),
        key: `d:${state.selectedImei}:${command.id ?? `${command.requestedAt}:${feature}`}`,
        time: whenShort(command.requestedAt) || "-",
        timeTitle: when(command.requestedAt),
    };
}

function renderConnectionTimeline(rows) {
    const events = rows
        .map(rowPayload)
        .filter((event) =>
            ["device.connected", "device.disconnected"].includes(
                String(event?.type || ""),
            ),
        )
        .sort((a, b) => eventTime(a) - eventTime(b));

    // Um evento só não é uma série, e a pastilha do dispositivo já diz se está ligado: a
    // secção fica escondida até haver o que desenhar.
    els.connectionSection.classList.toggle("d-none", events.length < 2);

    // Redesenhar é deitar o gráfico abaixo e construir outro, e isto passa por aqui a cada
    // tecla e a cada mensagem do stream.
    const signature = events
        .map((event) => `${event.type}@${eventTime(event)}`)
        .join("|");
    if (els.connectionTimeline.dataset.connectionSignature === signature) {
        return;
    }
    els.connectionTimeline.dataset.connectionSignature = signature;

    if (events.length < 2) {
        els.connectionTimeline.innerHTML = "";
        return;
    }

    els.connectionTimeline.innerHTML = connectionTimelineHtml(events);
}

/**
 * A série de ligações: um ponto por evento, verde a ligar e vermelho a desligar, com as duas
 * pontas datadas. As cores saem das variáveis do tema, para seguir o modo claro e o escuro.
 */
function connectionTimelineHtml(events) {
    const points = events
        .map((event) => ({
            time: eventTime(event),
            at: event.occurredAt || event.recordedAt || "",
            connected: event.type === "device.connected",
        }))
        .filter((point) => point.time > 0);
    if (points.length < 2) {
        return "";
    }

    const first = points[0].time;
    // Nunca zero: dois eventos no mesmo milissegundo dividiriam por zero.
    const span = Math.max(1, points[points.length - 1].time - first);

    const dots = points
        .map((point) => {
            const label = point.connected ? "Ligado" : "Desligado";
            return html`<span class="connection-timeline-dot position-absolute top-50 rounded-circle${point.connected ? "" : " off"}"
                        style="left:${((point.time - first) / span) * 100}%"
                        title="${label} em ${when(point.at)}"></span>`;
        })
        .join("");

    return html`
        <div class="connection-timeline position-relative">
            <div class="connection-timeline-track position-absolute top-50 start-0 end-0"></div>
            ${raw(dots)}
        </div>
        <div class="connection-timeline-scale d-flex justify-content-between gap-2 text-secondary tabular-nums">
            <span>${when(points[0].at)}</span>
            <span>${when(points[points.length - 1].at)}</span>
        </div>`;
}

function expectedReplies(command) {
    return Array.isArray(command.expectedReplyTypes) &&
        command.expectedReplyTypes.length
        ? `À espera de ${command.expectedReplyTypes.join(", ")}`
        : "";
}

async function requestTelemetryFeature(feature) {
    state.loadingCommands.add(feature);
    renderSelection();
    try {
        const result = await apiRequestFeature(state.selectedImei, feature);
        if (result.error) toast("error", apiError(result));
        // O comando novo chega pela via do stream (onCommandsUpdated); não se relê o
        // dispositivo -- e derrubar o stream para um snapshot completo era o custo a evitar.
    } finally {
        state.loadingCommands.delete(feature);
        renderSelection();
    }
}

function saveSelectedDeviceToStorage() {
    if (state.selectedImei) {
        saveTextStorage(SELECTED_DEVICE_STORAGE_KEY, state.selectedImei);
    }
}

function clearSelectedDeviceFromStorage() {
    clearStorageKey(SELECTED_DEVICE_STORAGE_KEY);
}

export {
    allDetailItems,
    applyDetailFilters,
    applyDetailSearch,
    clearDetailFilters,
    removeDetailFilter,
    clearSelectedDeviceFromStorage,
    detailFilterChipLabels,
    initDeviceDetailView,
    filterDetailItems,
    renderDownlinkRequests,
    renderRequestCardGroup,
    renderSelection,
    renderTelemetryList,
    telemetryRequestCards,
    saveSelectedDeviceToStorage,
    requestTelemetryFeature,
    updateDetailFilterDraft,
};
