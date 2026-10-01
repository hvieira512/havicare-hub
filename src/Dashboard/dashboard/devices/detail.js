import {
    requestFeature as apiRequestFeature,
} from "../api/index.js";
import {
    resetDetailFiltersDraft,
    setDownlinkPage,
    setTelemetryPage,
    state,
} from "../state.js";
import { deviceTypeLabel, normalizeDeviceType } from "../domain.js";
import {
    commandError,
    commandLabel,
    eventTime,
    rowPayload,
    timeOnly,
    when,
} from "../format.js";
import { html, raw } from "../html.js";
import { renderInto } from "../dom.js";
import { capabilityLabel } from "../capability-catalog.js";
import { apiError, toast } from "../dialogs.js";
import { deviceLicenseBlock, deviceLicenseLabel } from "../components/device-license.js";
import { onlineBadge } from "../components/state-badge.js";
import { cardTone, uplinkCardContent } from "../components/cards/telemetry.js";
import { telemetryCard } from "../components/cards/shell.js";
import {
    requestCardContent,
    requestCardShell,
    statusBadge,
} from "../components/cards/request.js";
import { fallSummaryCard, helpCallSummaryCard } from "./event-summary-cards.js";
import { onRadarPresence } from "./radar-map-modal.js";
import { activityTable } from "./activity-table.js";
import { protocolHelpCallPressModes } from "./config/protocol-catalog.js";
import { renderPagination } from "../pagination.js";
import { loadMoreButton, pagedRows } from "../components/pagination.js";
import { SELECTED_DEVICE_STORAGE_KEY, clearStorageKey, saveTextStorage } from "../storage.js";
import { disposeTooltips, refreshTooltips } from "../tooltips.js";
import { gatewaySignalRows } from "./gateway-signal.js";
import {
    allDetailItems,
    filterDetailItems,
    initDetailFilters,
    populateDetailFilterTypes,
    syncDetailFilterControls,
} from "./detail-filters.js";

const NCS_EVENT_CARD_TYPES = ["help_call", "reset"];

let els;

function initDeviceDetailView(context) {
    els = context.els;
    // Tudo o que redesenha entra por aqui e não por um import de volta: os filtros reduzem a
    // lista e paginam-na, o ecrã é que a desenha, e o grafo de módulos fica sem ciclos.
    initDetailFilters({
        els,
        onChange: renderSelection,
        renderDownlinkRequests,
        renderTelemetryList,
    });
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
    // Abaixo do `lg` a banda e a régua substituem o cartão da identidade, e sem dispositivo
    // escolhido não há identidade nenhuma para elas dizerem.
    els.deviceBand.classList.toggle("d-none", !state.selectedDetail);
    els.deviceTabs.classList.toggle("d-none", !state.selectedDetail);
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
    // A planta aberta acompanha o stream: as posições que acabaram de chegar são as mesmas
    // que o mosaico da presença acabou de desenhar.
    onRadarPresence(device.imei);
}

const TELEMETRY_REQUEST_GROUPS = [
    {
        key: "telemetry",
        label: "Últimas leituras",
    },
    {
        key: "system",
        label: "Informação do sistema",
    },
];

/** O que o aparelho diz sobre si próprio, e não uma medição do mundo. */
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
    // O resumo já a mostra em «Dispositivos ligados», uma linha por gateway e com barras. O
    // mosaico dizia-a pior: um só, e sem nomear o gateway que a ouviu.
    "proximity",
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
                valueClass: "d-block text-truncate",
                noteClass: "d-block small text-body-secondary text-truncate",
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
    // Corta-se com reticências numa coluna estreita, e por isso o valor inteiro fica no `title`.
    els.selectedDeviceTitle.title = device.imei;
    // O estado é a primeira coisa que se pergunta sobre um dispositivo, e por isso vem
    // antes do identificador.
    els.selectedDeviceBadge.innerHTML = onlineBadge(device.online);
    els.selectedDeviceMeta.textContent = `${typeLabel} · ${supplier || "Sem fornecedor"} · ${model || "Sem modelo interno"}`;
    // A banda do telemóvel diz o mesmo numa linha: o estado primeiro, que é o que se
    // pergunta, e o fornecedor de fora, que o modelo já o implica.
    els.deviceBandTitle.textContent = device.imei;
    els.deviceBandTitle.title = device.imei;
    if (els.deviceBandThumb.dataset.previewKey !== previewKey) {
        els.deviceBandThumb.dataset.previewKey = previewKey;
        els.deviceBandThumb.innerHTML = image
            ? html`<img src="${image}" class="object-fit-contain" alt="${model || device.imei}">`
            : "<i class=\"fa-solid fa-microchip text-white-50\"></i>";
    }
    // O estado é a cor da bola e não uma palavra a repeti-la. Quem não vê a cor lê-o no
    // nome acessível, que é o que ali a bola é.
    const onlineLabel = device.online ? "Ligado" : "Desligado";
    els.deviceBandDot.classList.toggle("bg-success", !!device.online);
    els.deviceBandDot.classList.toggle("bg-secondary", !device.online);
    els.deviceBandDot.setAttribute("aria-label", onlineLabel);
    els.deviceBandDot.title = onlineLabel;
    els.deviceBandMeta.textContent = `${model || "Sem modelo interno"} · ${deviceLicenseLabel(device)}`;
    const factsHtml = facts
        .map(
            (item) => html`
        <div class="${item.wide ? "col-12" : "col-6"}">
            <dt class="mb-1">${item.label}</dt>
            <dd class="text-truncate mb-0"${raw(item.html ? "" : html` title="${item.value}"`)}>${item.html ? raw(item.html) : item.value}</dd>
        </div>
    `,
        )
        .join("");
    if (renderInto(els.selectedDeviceFacts, factsHtml, disposeTooltips)) {
        refreshTooltips(els.selectedDeviceFacts);
    }
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

    const listedRows = pagedRows(telemetry, {
        page: state.telemetryPage,
        pageSize: state.telemetryPageSize,
        cumulative: state.telemetryCumulative,
    });

    // Na pastilha do contador cabe o número e mais nada: o título já diz de quê. O separador
    // tem a sua, porque abaixo do `xl` o cabeçalho da coluna não se vê.
    const telemetryTotal = telemetry.length ? String(telemetry.length) : "";
    els.telemetryCount.textContent = telemetryTotal;
    els.telemetryTabCount.textContent = telemetryTotal;
    els.deviceTabReadingsCount.textContent = telemetryTotal;
    activityTable(
        els.telemetryList,
        listedRows.map(telemetryActivityRow),
        "Ainda não há leituras.",
        // O prefixo é por lista: as duas desenham-se ao mesmo tempo no mesmo documento, e
        // com o mesmo `activityRowDetail0` em cada uma ficavam dois elementos com o mesmo id.
        "telemetryRowDetail",
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

    const pagination = {
        total: totalRows,
        total_pages: totalPages,
        page: state[`${prefix}Page`],
        limit: state[`${prefix}PageSize`],
    };

    renderPagination({
        pagination,
        rootEl: root,
        summaryEl,
        controlsEl,
        actionPrefix: prefix,
        summary: (start, end, total) => `${start}–${end} de ${total}`,
    });

    const loadMoreEl = els[`${prefix}LoadMore`];
    if (loadMoreEl) {
        loadMoreEl.innerHTML = loadMoreButton({ pagination, actionPrefix: prefix });
    }
}

/**
 * O que a gaveta de uma linha mostra, em texto simples e uma linha por campo. A gaveta escapa
 * o que recebe, e por isso não pode levar marcação — um `<br>` aparecia à letra no ecrã.
 */
function detailExpanded(card, plainDetail) {
    if (card.detailsTitle) {
        return card.detailsTitle;
    }

    const fields = plainDetail.split(" · ");

    return fields.length > 1 ? fields.join("\n") : "";
}

export function telemetryActivityRow(payload) {
    const type = payload?.type || "telemetry";
    const data =
        payload?.data && typeof payload.data === "object" ? payload.data : {};
    const card = uplinkCardContent(type, data);
    // Os detalhes são os que cada renderizador declara, e não todos os campos do payload.
    const detail = card.details || "";
    // O `detailsTitle` existe quando a linha visível é um resumo: a presença mostra as
    // posturas e guarda para aqui as coordenadas e as pessoas que não couberam.
    const at = payload.occurredAt || payload.recordedAt;

    const plainDetail = plainText(detail);
    const detailText = card.detailsTitle || plainDetail;

    return {
        icon: card.icon,
        tone: cardTone(type),
        name: capabilityLabel(type),
        value: html`${card.rowValue || card.value}`,
        detail,
        detailKind: card.detailsKind || "text",
        detailTitle: detailText,
        expanded: detailExpanded(card, plainDetail),
        // O `seq` é monótono por dispositivo e lista, e esta tabela junta duas listas: sem o
        // tipo, a leitura 7 e o alarme 7 davam a mesma chave.
        key: `t:${state.selectedImei}:${type}:${payload?.seq ?? at}`,
        at,
        time: timeOnly(at) || "--:--",
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
    if (renderInto(els.requestGrid, grid, disposeTooltips)) refreshTooltips(els.requestGrid);
}

function renderRequestCardGroup(
    group,
    telemetry = [],
    showLabel = true,
    commands = [],
) {
    const cards = group.cards
        .map((command) =>
            requestCardShell(
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
        <span class="count-chip">${group.cards.length}</span>
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
    renderInto(els.ncsEventGrid, cards.map(renderNcsEventCard).join(""));
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
    const downlinkTotal = commands.length ? String(commands.length) : "";
    els.downlinkRequestCount.textContent = downlinkTotal;
    els.downlinkTabCount.textContent = downlinkTotal;
    els.deviceTabRequestsCount.textContent = downlinkTotal;

    // A maioria dos aparelhos -- radares, gateways, medidores de fralda -- não recebe pedido
    // nenhum, e metade do painel dizia permanentemente que não havia pedidos enquanto a lista
    // ao lado cortava "Alarme de sinais vit..." numa coluna de 34%. Sem pedidos, os eventos
    // ficam com a linha toda; com eles, volta a divisão a meio.
    const hasRequests = commands.length > 0;
    els.downlinkColumn?.classList.toggle("d-none", !hasRequests);
    els.telemetryColumn?.classList.toggle("col-xl-6", hasRequests);
    els.telemetryColumn?.classList.toggle("pe-xl-3", hasRequests);

    // Um separador só não é escolha nenhuma: a régua sai, e quem estava nos pedidos volta
    // aos eventos em vez de ficar num painel escondido. No telemóvel resta-lhe a telemetria,
    // e por isso só sai o separador dos pedidos.
    els.activityTabs?.classList.toggle("d-none", !hasRequests);
    els.deviceTabRequests?.classList.toggle("d-none", !hasRequests);
    if (!hasRequests && els.downlinkColumn?.classList.contains("active")) {
        globalThis.bootstrap?.Tab.getOrCreateInstance(els.telemetryColumnTab).show();
    }
    if (!hasRequests && els.deviceTabRequests?.classList.contains("active")) {
        els.deviceTabReadings.click();
    }

    // Paginado como os eventos recebidos: sem páginas, os pedidos antigos ficam atrás de
    // um scroll interno que ninguém vê.
    const totalPages = Math.max(
        1,
        Math.ceil(commands.length / state.downlinkPageSize),
    );
    setDownlinkPage(state.downlinkPage, totalPages);

    const listedRows = pagedRows(commands, {
        page: state.downlinkPage,
        pageSize: state.downlinkPageSize,
        cumulative: state.downlinkCumulative,
    });

    activityTable(
        els.downlinkRequests,
        listedRows.map(downlinkActivityRow),
        "Ainda não há pedidos.",
        "downlinkRowDetail",
    );

    renderClientPager("downlink", commands.length, totalPages);
}

/**
 * A leitura que respondeu ao pedido: a primeira da capacidade pedida a partir do instante em
 * que se pediu. Uma mais antiga respondeu a outro pedido, e a última de todas seria a de
 * agora e não a desta linha.
 */
function commandReply(command) {
    const feature = String(command.feature || "");
    const acked = Date.parse(command.ackedAt || "");
    if (feature === "" || Number.isNaN(acked)) return null;

    const requested = Date.parse(command.requestedAt || "") || acked;
    const reply = (state.selectedDetail?.recent?.telemetry || [])
        .map(rowPayload)
        .filter(
            (payload) =>
                payload &&
                String(payload.type || "") === feature &&
                eventTime(payload) >= requested,
        )
        .sort((left, right) => eventTime(left) - eventTime(right))[0];

    return reply ? uplinkCardContent(feature, reply.data || {}) : null;
}

/** Quando se pediu, com segundos, e quanto tempo a resposta demorou a chegar. */
function commandTiming(command) {
    const requested = Date.parse(command.requestedAt || "");
    if (Number.isNaN(requested)) return "";

    const at = new Date(requested).toLocaleTimeString("pt-PT");
    const acked = Date.parse(command.ackedAt || "");
    if (Number.isNaN(acked)) return `Pedido às ${at}`;

    const seconds = Math.max(0, Math.round((acked - requested) / 1000));
    const elapsed = seconds < 60
        ? `${seconds} s`
        : `${Math.round(seconds / 60)} min`;

    return `Pedido às ${at}, respondeu ${elapsed} depois`;
}

function downlinkActivityRow(command) {
    const feature = String(command.feature || "");
    const replied = command.ackedAt
        ? `Resposta ${when(command.ackedAt)}`
        : command.sentAt
            ? `Enviado ${when(command.sentAt)}`
            : expectedReplies(command);
    const note = commandError(command.error);
    const reply = commandReply(command);
    // O valor da resposta vai por baixo do nome: a coluna do valor leva a pastilha do estado,
    // e o nome é onde há espaço para ele.
    const value = reply ? String(reply.rowValue || reply.value || "") : "";
    const replyDetails = plainText(reply?.detailsTitle || reply?.details || "");

    return {
        icon: requestCardContent(feature).icon,
        tone: cardTone(feature),
        name: commandLabel(command) || requestCardContent(feature).value || "Pedido",
        sub: value
            ? html`<span class="fw-semibold text-body">${value}</span>`
            : note ? html`${note}` : "",
        subTitle: value || note,
        value: statusBadge(String(command.status || "unknown")),
        valueTitle: replied,
        expanded: [
            replyDetails,
            commandTiming(command),
            command.ackedAt ? "" : replied,
            note,
        ].filter(Boolean).join("\n"),
        key: `d:${state.selectedImei}:${command.id ?? `${command.requestedAt}:${feature}`}`,
        at: command.requestedAt,
        time: timeOnly(command.requestedAt) || "--:--",
        timeTitle: when(command.requestedAt),
    };
}

/** A gaveta escapa o que recebe: os detalhes chegam com marcação e saem em texto. */
function plainText(markup) {
    return String(markup)
        .replace(/<br\s*\/?>/gi, " · ")
        .replace(/<[^>]*>/g, "");
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
        // dispositivo, porque derrubar o stream para um snapshot completo é o custo a evitar.
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
    clearSelectedDeviceFromStorage,
    downlinkActivityRow,
    initDeviceDetailView,
    renderDownlinkRequests,
    renderRequestCardGroup,
    renderSelection,
    renderTelemetryList,
    telemetryRequestCards,
    saveSelectedDeviceToStorage,
    requestTelemetryFeature,
};
