import {
    requestFeature as apiRequestFeature,
} from "../api/index.js";
import {
    setDownlinkPage,
    setTelemetryPage,
    state,
} from "../state.js";
import {deviceTypeLabel, normalizeDeviceType} from "../domain.js";
import {
    commandLabel,
    esc,
    eventTime,
    fieldLabel,
    rowPayload,
    when,
    whenShort,
} from "../format.js";
import {capabilityLabel} from "../capability-catalog.js";
import {deviceLicenseHtml, emptyPanel, filterChips} from "../widgets.js";
import {
    cardTone,
    telemetryCard,
    fallSummaryCard,
    helpCallSummaryCard,
    renderRequestCardShell,
    requestCardContent,
    statusBadge,
    uplinkCardContent,
} from "../telemetry-cards.js";
import {renderPagination} from "../pagination.js";
import {clearStorageKey, saveTextStorage} from "../storage.js";
import {disposeTooltips, refreshTooltips} from "../tooltips.js";
import {gatewaySignalRows} from "./gateway-signal.js";

const DETAIL_ITEM_TYPES = {
    "device.connected": () => "device.connected",
    "device.disconnected": () => "device.disconnected",
};

const NCS_EVENT_CARD_TYPES = ["help_call", "reset"];

/** Os alarmes que a lista de actividade mostra: os do NCS e os três do radar. */
const ALARM_EVENT_TYPES = new Set([
    "help_call",
    "reset",
    "fall",
    "vitals_alarm",
    "presence_event",
]);

let els;
let loadDeviceFn = async () => false;
let connectionChartRoot = null;

function initDeviceDetailView(context) {
    els = context.els;
    loadDeviceFn = context.loadDevice || loadDeviceFn;
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
        els.ncsEventCardCount.textContent = "";
        els.ncsEventGrid.innerHTML = "";
        els.ncsEventSection.classList.add("d-none");
        return;
    }

    if (!state.detailFiltersDraft || typeof state.detailFiltersDraft !== "object") {
        state.detailFiltersDraft = { ...state.detailFilters };
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
        els.ncsEventCardCount.textContent = "";
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
 * Capacidades que existem, mas não como mosaico próprio: o `diaper_moisture_level` é o
 * índice 0-100 da humidade, e mostra-se como valor do cartão dos canais.
 */
/**
 * Capacidades que o dispositivo tem mas que o mosaico de resumo não mostra: o resumo diz o
 * estado agora, e os dois agregados do radar são médias do último minuto. Continuam na
 * lista de eventos, onde a coluna do tempo lhes dá o sentido que um mosaico não dá.
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
        { label: "Licença", html: deviceLicenseHtml(device) },
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

    els.selectedDevicePreview.innerHTML = image
        ? `<img src="${esc(image)}" class="object-fit-contain" alt="${esc(model || device.imei)}" style="max-width:56px;max-height:56px;">`
        : '<i class="fa-solid fa-microchip fa-xl text-secondary"></i>';
    els.selectedDeviceTitle.textContent = device.imei;
    // O estado é a primeira coisa que se pergunta sobre um dispositivo, e por isso vem
    // antes do identificador.
    els.selectedDeviceBadge.className = `config-state ${device.online ? "config-state-success" : "config-state-secondary"}`;
    els.selectedDeviceBadge.innerHTML =
        `<span class="config-state-dot"></span>${device.online ? "Ligado" : "Desligado"}`;
    els.selectedDeviceMeta.textContent = `${typeLabel} · ${supplier || "Sem fornecedor"} · ${model || "Sem modelo interno"}`;
    disposeTooltips(els.selectedDeviceFacts);
    els.selectedDeviceFacts.innerHTML = facts
        .map(
            (item) => `
        <div class="${item.wide ? "col-12" : "col-6"}">
            <dt class="mb-1">${esc(item.label)}</dt>
            <dd class="text-break mb-0">${item.html ?? esc(item.value)}</dd>
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

/** O que a linha mostra, em minúsculas: o tipo e o valor formatado. */
function detailItemHaystack(item) {
    const itemType = detailItemType(item);
    const content = uplinkCardContent(itemType, item.payload?.data || {});
    return `${itemType} ${fieldLabel(itemType)} ${content?.value ?? ""}`.toLowerCase();
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
                select.insertAdjacentHTML(
                    "beforeend",
                    `<option value="${esc(type)}">${esc(telemetryFilterLabel(type))}</option>`,
                );
            }
        } else {
            select.innerHTML = [
                '<option value="all">Todos</option>',
                ...observedTypes.map(
                    (type) =>
                        `<option value="${esc(type)}">${esc(telemetryFilterLabel(type))}</option>`,
                ),
            ].join("");
        }

        select.dataset.detailFilterTypesSignature = signature;
    }

    if (currentValue && currentValue !== "all") {
        if (!hasCurrentValue) {
            select.insertAdjacentHTML(
                "beforeend",
                `<option value="${esc(currentValue)}">${esc(telemetryFilterLabel(currentValue))}</option>`,
            );
        }
        select.value = currentValue;
        return;
    }

    select.value = "all";
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
    state.detailFiltersDraft = { ...state.detailFilters };
    state.telemetryPage = 1;
    renderSelection();
}

function clearDetailFilters() {
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
    state.detailFiltersDraft = { ...state.detailFilters };
    state.telemetryPage = 1;
    if (els.detailSearch) els.detailSearch.value = "";
    renderSelection();
}

/**
 * A pesquisa não espera pelo "Aplicar". Os selects de data e tipo têm botão porque uma
 * data a meio de ser escrita não é uma data; um texto a meio já é um prefixo útil.
 */
function applyDetailSearch() {
    state.detailFilters = {
        ...state.detailFilters,
        q: els.detailSearch.value,
    };
    state.detailFiltersDraft = { ...state.detailFiltersDraft, q: els.detailSearch.value };
    state.telemetryPage = 1;
    renderSelection();
}

function removeDetailFilter(key) {
    const cleared = key === "type" ? "all" : "";
    state.detailFilters = { ...state.detailFilters, [key]: cleared };
    state.detailFiltersDraft = { ...state.detailFilters };
    state.telemetryPage = 1;
    if (key === "q" && els.detailSearch) els.detailSearch.value = "";
    renderSelection();
}

/** As pastilhas do que está aplicado, na linha abaixo da pesquisa. */
function renderDetailActiveFilters() {
    const { from, to, type, q } = state.detailFilters;
    const labels = [];
    if (from || to) {
        labels.push({
            key: from && to ? "range" : from ? "from" : "to",
            label: `${from ? when(from) : "início"} → ${to ? when(to) : "agora"}`,
        });
    }
    if (type && type !== "all") {
        labels.push({key: "type", label: fieldLabel(type) || type});
    }
    if (String(q || "").trim() !== "") {
        labels.push({key: "q", label: `"${q.trim()}"`});
    }

    els.detailActiveFilters.innerHTML = filterChips(labels, "removeDetailFilter");
    els.detailFilterCount.textContent = labels.length ? String(labels.length) : "";
    els.detailFilterCount.classList.toggle("d-none", labels.length === 0);
    els.clearDetailFiltersBtn.classList.toggle("d-none", labels.length === 0);
    // Sem filtros aplicados a linha inteira sai, para não sobrar espaço sem conteúdo.
    els.detailActiveFiltersRow?.classList.toggle("d-none", labels.length === 0);
}

function updateDetailFilterDraft() {
    state.detailFiltersDraft = {
        from: els.detailFilterFrom.value,
        to: els.detailFilterTo.value,
        type: els.detailFilterType.value,
        q: state.detailFilters.q,
    };
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
    // Uma linha por evento, em colunas: pastilha do ícone, nome, valor, hora.
    els.telemetryList.innerHTML = pageRows.length
        ? `<table class="table table-sm align-middle mb-0 telemetry-table">
            <tbody>${pageRows.map(renderTelemetryRow).join("")}</tbody>
           </table>`
        : emptyPanel("Ainda não há eventos recebidos.");
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
    if (!root || !summaryEl || !controlsEl) return;

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

function renderTelemetryRow(payload) {
    const type = payload?.type || "telemetry";
    const data =
        payload?.data && typeof payload.data === "object" ? payload.data : {};
    const card = uplinkCardContent(type, data);
    // Os detalhes são os que cada renderizador declara, e não todos os campos do payload.
    const details = card.details || "";
    // O `detailsTitle` existe quando a linha visível é um resumo: a presença mostra as
    // posturas e guarda para aqui as coordenadas e as pessoas que não couberam.
    const detailsTitle = card.detailsTitle
        || details.replace(/<br\s*\/?>/gi, " · ").replace(/<[^>]*>/g, "");

    const tone = cardTone(type);
    return `
        <tr>
        <td>
            <span class="telemetry-row-icon${tone ? ` telemetry-card-tone-${esc(tone)}` : ""}">
                <i class="fa-solid ${esc(card.icon)}"></i>
            </span>
        </td>
        <td class="fw-medium" title="${esc(capabilityLabel(type))}">${esc(capabilityLabel(type))}</td>
        <td class="tabular-nums">
            <span class="telemetry-row-stack">
                <span class="d-block text-truncate">${esc(card.rowValue || card.value)}</span>
                ${details ? `<span class="telemetry-row-details d-flex flex-wrap gap-1" title="${esc(detailsTitle)}">${details}</span>` : ""}
            </span>
        </td>
        <td class="text-end text-nowrap tabular-nums text-secondary" title="${esc(when(payload.occurredAt || payload.recordedAt))}">${esc(whenShort(payload.occurredAt || payload.recordedAt) || "hora desconhecida")}</td>
        </tr>`;
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
    // Tirado do histórico de eventos e não de uma capacidade, para aparecer exactamente
    // quando o dispositivo pediu ajuda.
    const helpCalls = helpCallSummaryCard(events);
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
    els.requestGrid.innerHTML = falls + helpCalls + cards || `<div class="col-12">${emptyPanel("Não há pedidos disponíveis para este dispositivo.")}</div>`;
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

    return `
        <div class="col-12">
        <div class="border rounded-3 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="section-label">${esc(group.label || "Pedidos")}</div>
        <span class="count-chip">${group.cards.length}</span>
        </div>
        <div class="row g-3">${cards}</div>
        </div>
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

    els.ncsEventSection.classList.toggle("d-none", cards.length === 0);
    els.ncsEventCardCount.textContent = cards.length
        ? `${cards.length} eventos`
        : "";
    els.ncsEventGrid.innerHTML = cards.length
        ? cards.map(renderNcsEventCard).join("")
        : `<div class="col-12">${emptyPanel("Ainda não há eventos NCS recebidos.")}</div>`;
}

function renderNcsEventCard({type, latest}) {
    const content = uplinkCardContent(type, latest.data || {});
    const timestamp = when(latest.occurredAt || latest.recordedAt) || "hora desconhecida";
    const pagerId =
        latest.data && typeof latest.data === "object"
            ? String(latest.data.pagerId || "")
            : "";

    return telemetryCard({
        icon: content.icon,
        title: content.value,
        tone: cardTone(type),
        body: `
        <div class="small text-secondary mt-2">Último evento: ${esc(timestamp)}</div>
        ${pagerId ? `<div class="small text-secondary">Pager: ${esc(pagerId)}</div>` : ""}`,
    });
}

function renderDownlinkRequests(commands) {
    els.downlinkRequestCount.textContent = commands.length ? String(commands.length) : "";

    // Paginado como os eventos recebidos: sem páginas, os pedidos antigos ficam atrás de
    // um scroll interno que ninguém vê.
    const totalPages = Math.max(
        1,
        Math.ceil(commands.length / state.downlinkPageSize),
    );
    setDownlinkPage(state.downlinkPage, totalPages);

    const start = (state.downlinkPage - 1) * state.downlinkPageSize;
    const pageRows = commands.slice(start, start + state.downlinkPageSize);

    // A mesma linha dos eventos recebidos, do outro lado: pastilha do ícone, nome, estado,
    // hora. A resposta cabe no `title` da pastilha, e o erro na segunda linha do nome.
    els.downlinkRequests.innerHTML = pageRows.length
        ? `<table class="table table-sm align-middle mb-0 telemetry-table">
            <tbody>${pageRows.map(renderDownlinkRow).join("")}</tbody>
           </table>`
        : emptyPanel("Ainda não há pedidos ao dispositivo.");

    renderClientPager("downlink", commands.length, totalPages);
}

function renderDownlinkRow(command) {
    const status = String(command.status || "unknown");
    const feature = String(command.feature || "");
    const content = requestCardContent(feature);
    const tone = cardTone(feature);
    const replied = command.ackedAt
        ? `Resposta ${when(command.ackedAt)}`
        : command.sentAt
          ? `Enviado ${when(command.sentAt)}`
          : expectedReplies(command);
    const note = command.error || "";
    return `
        <tr>
        <td>
            <span class="telemetry-row-icon${tone ? ` telemetry-card-tone-${esc(tone)}` : ""}">
                <i class="fa-solid ${esc(content.icon)}"></i>
            </span>
        </td>
        <td class="fw-medium">
            <span class="telemetry-row-stack">
                <span class="d-block text-truncate" title="${esc(commandLabel(command) || content.value || "Pedido")}">${esc(commandLabel(command) || content.value || "Pedido")}</span>
                ${note ? `<span class="telemetry-row-details fw-normal d-block text-truncate" title="${esc(note)}">${esc(note)}</span>` : ""}
            </span>
        </td>
        <td${replied ? ` title="${esc(replied)}"` : ""}>${statusBadge(status)}</td>
        <td class="text-end text-nowrap tabular-nums text-secondary" title="${esc(when(command.requestedAt))}">${esc(whenShort(command.requestedAt) || "-")}</td>
        </tr>`;
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

    if (events.length < 2) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.connectionTimeline.innerHTML = "";
        return;
    }

    if (connectionChartRoot) {
        connectionChartRoot.dispose();
    }

    connectionChartRoot = am5.Root.new(els.connectionTimeline);
    connectionChartRoot._logo?.dispose();

    connectionChartRoot.setThemes([
        am5themes_Animated.new(connectionChartRoot),
    ]);

    const chart = connectionChartRoot.container.children.push(
        am5xy.XYChart.new(connectionChartRoot, {
            panX: false,
            panY: false,
            wheelX: "none",
            wheelY: "none",
            paddingTop: 8,
            paddingBottom: 8,
            paddingLeft: 0,
            paddingRight: 0,
        }),
    );

    const dateAxis = chart.xAxes.push(
        am5xy.DateAxis.new(connectionChartRoot, {
            baseInterval: { timeUnit: "minute", count: 1 },
            renderer: am5xy.AxisRendererX.new(connectionChartRoot, {
                minGridDistance: 60,
            }),
            tooltip: am5.Tooltip.new(connectionChartRoot, {}),
        }),
    );
    dateAxis.get("renderer").grid.template.set("visible", false);

    const valueAxis = chart.yAxes.push(
        am5xy.ValueAxis.new(connectionChartRoot, {
            renderer: am5xy.AxisRendererY.new(connectionChartRoot, {}),
            min: -0.2,
            max: 0.2,
            strictMinMax: true,
        }),
    );
    valueAxis.get("renderer").grid.template.set("visible", false);
    valueAxis.get("renderer").labels.template.set("forceHidden", true);
    valueAxis.get("renderer").set("visible", false);

    const data = connectionTimelineData(events);
    const series = chart.series.push(
        am5xy.LineSeries.new(connectionChartRoot, {
            name: "Ligação",
            xAxis: dateAxis,
            yAxis: valueAxis,
            valueYField: "value",
            valueXField: "date",
            stroke: am5.color(0x6c757d),
            strokeWidth: 2,
            tooltip: am5.Tooltip.new(connectionChartRoot, {
                labelText: '{label} em {valueX.formatDate("dd/MM/yyyy HH:mm")}',
            }),
        }),
    );
    series.data.setAll(data);

    series.bullets.push(function (_root, _series, dataItem) {
        const color = dataItem.dataContext?.bulletColor || "#6c757d";
        return am5.Bullet.new(connectionChartRoot, {
            sprite: am5.Circle.new(connectionChartRoot, {
                radius: 5,
                fill: am5.color(color),
                stroke: am5.color(0xffffff),
                strokeWidth: 1,
            }),
        });
    });

    dateAxis.start = 0;
    dateAxis.end = 1;

    chart.set(
        "cursor",
        am5xy.XYCursor.new(connectionChartRoot, {
            behavior: "none",
            xAxis: dateAxis,
        }),
    );
}

function connectionTimelineData(events) {
    return events
        .map((event) => {
            const isConnected = event.type === "device.connected";
            return {
                date: eventTime(event),
                value: 0,
                label: isConnected ? "Ligado" : "Desligado",
                bulletColor: isConnected ? "#198754" : "#dc3545",
            };
        })
        .filter((point) => point.date > 0);
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
        if (result.error) alert(result.error.message || result.error.code);
        if (state.selectedImei && typeof loadDeviceFn === "function") {
            await loadDeviceFn(state.selectedImei);
        }
    } finally {
        state.loadingCommands.delete(feature);
        renderSelection();
    }
}

function saveSelectedDeviceToStorage() {
    if (state.selectedImei) {
        saveTextStorage("hub-dashboard-selected-device", state.selectedImei);
    }
}

function clearSelectedDeviceFromStorage() {
    clearStorageKey("hub-dashboard-selected-device");
}

export {
    allDetailItems,
    applyDetailFilters,
    applyDetailSearch,
    clearDetailFilters,
    removeDetailFilter,
    clearSelectedDeviceFromStorage,
    initDeviceDetailView,
    filterDetailItems,
    renderDownlinkRequests,
    renderSelection,
    renderTelemetryList,
    saveSelectedDeviceToStorage,
    requestTelemetryFeature,
    updateDetailFilterDraft,
};
