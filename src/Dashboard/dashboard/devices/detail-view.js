import {
    requestFeature as apiRequestFeature,
} from "../api/index.js";
import {clearSelection, setTelemetryPage, state} from "../state.js";
import {
    companyLabel,
    deviceTypeLabel,
    deviceTypeOptions,
    licenseLabel,
    normalizeDeviceType,
} from "../domain.js";
import {
    commandLabel,
    displayValue,
    esc,
    eventTime,
    featureLabel,
    fieldLabel,
    rowPayload,
    when,
} from "../format.js";
import {
    emptyPanel,
    filterChips,
    telemetryCard,
    helpCallSummaryCard,
    renderRequestCardShell,
    statusBadge,
    uplinkCardContent,
} from "../renderers.js";
import {clearStorageKey, saveTextStorage} from "../core/storage.js";
import {disposeTooltips, refreshTooltips} from "../core/tooltips.js";
import {gatewaySignalRows} from "./gateway-signal.js";

const DETAIL_ITEM_TYPES = {
    "device.connected": () => "device.connected",
    "device.disconnected": () => "device.disconnected",
};

const NCS_EVENT_CARD_TYPES = ["help_call", "reset"];

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
    // Sem dispositivo escolhido, a coluna da atividade nao tem nada para dizer que a da
    // esquerda ja nao diga -- e essa tem o botao. Uma mensagem repetida em duas colunas
    // le-se como erro, por isso a coluna desaparece e a da escolha ocupa a largura.
    els.detailColumn.classList.toggle("d-none", !state.selectedDetail);
    els.deviceColumn.classList.toggle("col-lg-4", !!state.selectedDetail);
    if (!state.selectedDetail) {
        els.requestCardCount.textContent = "";
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

    if (!state.detailFilters.from) {
        const sevenDaysAgo = new Date();
        sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
        state.detailFilters.from = sevenDaysAgo.toISOString().slice(0, 16);
    }

    populateDetailFilterTypes();
    syncDetailFilterControls();

    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const deviceType = normalizeDeviceType(deviceModel?.deviceType || "watch");
    const ncsEvents = filtered
        .filter(
        (item) =>
            item._source === "event" &&
            (item.payload?.type === "help_call" ||
                item.payload?.type === "reset"),
        )
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

    renderTelemetryList([...telemetry, ...ncsEvents]);
    renderRequestCards(
        telemetryRequestCards(
            state.selectedDetail?.capabilities?.telemetry || {},
        ),
        telemetry,
        ncsEvents,
    );
    if (deviceType === "ncs") {
        renderNcsEventCards(ncsEvents);
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

function telemetryRequestCards(telemetryCapabilities = {}) {
    const cards = Object.entries(telemetryCapabilities || {})
        .filter(([, entry]) => entry?.supported)
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

            return String(featureLabel(a.feature || "")).localeCompare(
                String(featureLabel(b.feature || "")),
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
        { label: "Licença", value: licenseLabel(device.licenseId) },
        { label: "Empresa", value: companyLabel(device.company) },
        {
            label: "Última ligação",
            value: when(device.lastSeenAt) || "Sem registo",
        },
    ];

    if (device.simNumber) {
        facts.push({ label: "SIM", value: String(device.simNumber) });
    }
    if (linkedDevices.length) {
        // A bare list of MACs said nothing about whether those gateways can
        // currently hear the device, which is the useful part.
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
    // O estado e a primeira coisa que se pergunta sobre um dispositivo, e vem antes do
    // identificador. Era um ponto colorido sem palavra -- e o vermelho de "desligado"
    // competia com o vermelho de erro no resto do ecra.
    els.selectedDeviceBadge.className = `config-state ${device.online ? "config-state-success" : "config-state-secondary"}`;
    els.selectedDeviceBadge.innerHTML =
        `<span class="config-state-dot"></span>${device.online ? "Ligado" : "Desligado"}`;
    els.selectedDeviceMeta.textContent = `${typeLabel} · ${supplier || "Sem fornecedor"} · ${model || "Sem modelo interno"}`;
    disposeTooltips(els.selectedDeviceFacts);
    els.selectedDeviceFacts.innerHTML = facts
        .map(
            (item) => `
        <div class="${item.wide ? "col-12" : "col-6"}">
            <dt>${esc(item.label)}</dt>
            <dd class="text-break">${item.html ?? esc(item.value)}</dd>
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
        if (payload && !payload.debug)
            items.push({ _source: "telemetry", raw: row, payload });
    }
    for (const row of recent.events || []) {
        const payload = rowPayload(row);
        if (!payload) continue;
        if (
            payload.type === "help_call" ||
            payload.type === "reset"
        )
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
    // A pesquisa corre sobre o que esta carregado, que e a janela escolhida nas datas --
    // nao ha procura no historico todo porque o filtro de atividade sempre foi do lado do
    // cliente. Compara com o que a pessoa ve na linha: o tipo e o valor formatado.
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

/** O que a linha mostra, em minusculas: o tipo e o valor formatado. */
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
    return featureLabel(type) || type;
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
 * A pesquisa nao espera pelo "Aplicar": escreve-se e a lista responde.
 *
 * Os selects de data e tipo tem um botao porque uma data a meio de ser escrita nao e uma
 * data; um texto a meio de ser escrito ja e um prefixo util.
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

/** As pastilhas do que esta aplicado, na linha abaixo da pesquisa. */
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

    els.telemetryCount.textContent = telemetry.length
        ? `${telemetry.length} eventos`
        : "";
    els.telemetryList.innerHTML = pageRows.length
        ? `<div class="list-group">${pageRows.map(renderTelemetryRow).join("")}</div>`
        : emptyPanel("Ainda não há eventos recebidos.");
    renderTelemetryPager(telemetry.length, totalPages);
}

function renderTelemetryPager(totalRows, totalPages) {
    const root = els.telemetryPager;
    const summaryEl = els.telemetryPagerSummary;
    const controlsEl = els.telemetryPagerControls;

    if (totalRows <= state.telemetryPageSize) {
        root.classList.add("d-none");
        summaryEl.textContent = "";
        controlsEl.innerHTML = "";
        return;
    }

    const currentPage = state.telemetryPage;
    const limit = state.telemetryPageSize;
    const pageStart = (currentPage - 1) * limit + 1;
    const pageEnd = Math.min(totalRows, currentPage * limit);
    root.classList.remove("d-none");
    summaryEl.textContent = `${pageStart}–${pageEnd} de ${totalRows}`;
    controlsEl.innerHTML = [
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPrev" ${currentPage <= 1 ? "disabled" : ""} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>`,
        ...Array.from({ length: totalPages }, (_, index) => {
            const page = index + 1;
            return `<button type="button" class="btn ${page === currentPage ? "btn-primary" : "btn-outline-secondary"} btn-sm" data-action="telemetryPageGo" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ""}>${page}</button>`;
        }),
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryNext" ${currentPage >= totalPages ? "disabled" : ""} aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>`,
    ].join("");
}

function renderTelemetryRow(payload) {
    const type = payload?.type || "telemetry";
    const data =
        payload?.data && typeof payload.data === "object" ? payload.data : {};
    const card = uplinkCardContent(type, data);
    const details = telemetryDetails(data, payload);

    return `
        <div class="list-group-item">
        <div class="d-flex justify-content-between gap-3">
        <div class="min-width-0">
        <div class="fw-semibold"><i class="fa-solid ${esc(card.icon)} text-secondary me-2"></i>${esc(featureLabel(type))}</div>
        <div class="small text-secondary">${esc(payload.source?.nativeType || "telemetria")}</div>
        </div>
        <div class="text-end flex-shrink-0">
        <div class="fw-semibold">${esc(card.value)}</div>
        <div class="small text-secondary">${esc(when(payload.occurredAt || payload.recordedAt) || "hora desconhecida")}</div>
        </div>
        </div>
        ${details ? `<div class="small text-secondary mt-2 text-break">${details}</div>` : ""}
        </div>`;
}

function telemetryDetails(data, payload) {
    if (payload?.type === "position") {
        return radarPositionDetails(data);
    }

    const details = [];
    const skipKeys =
        payload?.type === "help_call" ||
        payload?.type === "reset"
            ? new Set(["event", "alarm"])
            : new Set();
    if (data && typeof data === "object") {
        for (const [key, value] of Object.entries(data)) {
            if (value === undefined || value === null || value === "") continue;
            if (skipKeys.has(key)) continue;
            details.push(`${fieldLabel(key)}: ${esc(displayValue(value))}`);
        }
    }
    if (payload?.extra && typeof payload.extra === "object") {
        details.push(
            ...Object.entries(payload.extra)
                .filter(
                    ([, value]) =>
                        value !== undefined && value !== null && value !== "",
                )
                .slice(0, 6)
                .map(
                    ([key, value]) =>
                        `${fieldLabel(key)}: ${esc(displayValue(value))}`,
                ),
        );
    }
    return details.join(" · ");
}

function radarPositionDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    if (!people.length) {
        return "Pessoas: 0";
    }

    const countLabel = `Pessoas: ${people.length}`;
    const personLines = people.map((person, index) => {
        const personIndex = person?.person_index ?? index + 1;
        const x = displayValue(person?.x_position_dm);
        const y = displayValue(person?.y_position_dm);
        const z = displayValue(person?.z_position_cm);
        const posture = displayValue(person?.posture_state);

        return [
            `Pessoa ${esc(personIndex)}`,
            `x: ${esc(x)} dm`,
            `y: ${esc(y)} dm`,
            `z: ${esc(z)} cm`,
            `postura: ${esc(posture)}`,
        ].join(" · ");
    });

    return [countLabel, ...personLines].join("<br>");
}

function renderRequestCards(groups, telemetry = [], events = []) {
    const totalCards = groups.reduce(
        (count, group) => count + group.cards.length,
        0,
    );
    // Rendered from the event history rather than a capability, so it appears
    // exactly when the device has actually called for help.
    const helpCalls = helpCallSummaryCard(events);

    els.requestCardCount.textContent = totalCards
        ? `${totalCards} ações`
        : "";
    disposeTooltips(els.requestGrid);

    const cards = totalCards
        ? groups.map((group) => renderRequestCardGroup(group, telemetry)).join("")
        : "";
    els.requestGrid.innerHTML = helpCalls + cards || `<div class="col-12">${emptyPanel("Não há pedidos disponíveis para este dispositivo.")}</div>`;
    refreshTooltips(els.requestGrid);
}

function renderRequestCardGroup(group, telemetry = []) {
    return `
        <div class="col-12">
        <div class="border rounded-3 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="section-label">${esc(group.label || "Pedidos")}</div>
        <span class="small text-secondary">${group.cards.length}</span>
        </div>
        <div class="row g-3">
        ${group.cards
            .map((command) =>
                renderRequestCardShell(
                    command,
                    state.loadingCommands.has(
                        String(
                            command.id ||
                                command.feature ||
                                command.command ||
                                "",
                        ),
                    ),
                    telemetry,
                ),
            )
            .join("")}
        </div>
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
        body: `
        <div class="small text-secondary mt-2">Último evento: ${esc(timestamp)}</div>
        ${pagerId ? `<div class="small text-secondary">Pager: ${esc(pagerId)}</div>` : ""}`,
    });
}

function renderDownlinkRequests(commands) {
    els.downlinkRequestCount.textContent = commands.length
        ? `${commands.length} ${commands.length === 1 ? "pedido" : "pedidos"}`
        : "";
    els.downlinkRequests.innerHTML = commands.length
        ? `
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
        <thead>
        <tr><th>Pedido em</th><th>Pedido</th><th>Estado</th><th>Resposta</th><th>Detalhes</th></tr>
        </thead>
        <tbody>
        ${commands.map(renderDownlinkRow).join("")}
        </tbody>
        </table>
        </div>`
        : emptyPanel("Ainda não há pedidos ao dispositivo.");
}

function renderDownlinkRow(command) {
    const status = String(command.status || "unknown");
    return `
        <tr>
        <td class="text-nowrap small">${esc(when(command.requestedAt) || "-")}</td>
        <td><div class="fw-semibold">${esc(commandLabel(command) || "Pedido")}</div><div class="small text-secondary">${esc(command.nativeType || "")}</div></td>
        <td>${statusBadge(status)}</td>
        <td class="small">${esc(command.ackedAt ? when(command.ackedAt) : command.sentAt ? when(command.sentAt) : "-")}</td>
        <td class="small text-secondary">${esc(command.error || command.replyNativeType || expectedReplies(command))}</td>
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

    const connectedCount = events.filter(
        (e) => e.type === "device.connected",
    ).length;
    const disconnectedCount = events.filter(
        (e) => e.type === "device.disconnected",
    ).length;

    if (events.length < 2) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.connectionTimeline.innerHTML =
            events.length === 1
                ? `<div class="text-center text-secondary py-4"><i class="fa-solid fa-circle ${events[0].type === "device.connected" ? "text-success" : "text-secondary"} me-2"></i>${events[0].type === "device.connected" ? "Ligado" : "Desligado"} · ${esc(when(events[0].occurredAt || events[0].recordedAt))}</div>`
                : "";
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
    renderConnectionTimeline,
    renderDownlinkRequests,
    renderRequestCards,
    renderSelection,
    renderSelectedDeviceSummary,
    renderTelemetryList,
    saveSelectedDeviceToStorage,
    requestTelemetryFeature,
    telemetryRequestCards,
    updateDetailFilterDraft,
};
