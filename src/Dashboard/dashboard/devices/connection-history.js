import { html } from "../html.js";
import { resolvePaginationPage } from "../pagination.js";
import { disposeTooltips, refreshTooltips } from "../tooltips.js";
import { loadMoreButton, pagedRows, paginationControls } from "../components/pagination.js";

/**
 * As ligações do dispositivo escolhido: uma faixa com as quebras a vermelho e, por baixo, a
 * lista delas, fechada até se carregar na seta do título.
 */

const PAGE_SIZE = 5;
const MINUTE = 60000;

/** O que cada contentor mostra e em que página está, para o clique o poder redesenhar. */
const views = new WeakMap();

/**
 * As quebras entre o primeiro evento e o `end`, da mais recente para a mais antiga. Uma quebra
 * abre no primeiro desligar e fecha no ligar seguinte; sem ele dura até ao `end`.
 */
export function connectionOutages(events, { now = Date.now(), end = now } = {}) {
    const timeline = events
        .map((event) => ({
            time: Date.parse(event?.occurredAt || event?.recordedAt || ""),
            connected: event?.type === "device.connected",
            known: ["device.connected", "device.disconnected"].includes(event?.type),
        }))
        .filter((point) => point.known && point.time > 0)
        .sort((a, b) => a.time - b.time);

    const outages = [];
    let open = null;
    for (const point of timeline) {
        if (!point.connected && open === null) {
            open = point.time;
        } else if (point.connected && open !== null) {
            outages.push({ from: open, to: point.time });
            open = null;
        }
    }
    if (open !== null) outages.push({ from: open, to: null });

    const start = timeline[0]?.time ?? null;
    const total = start === null ? 0 : end - start;
    const offline = outages.reduce((sum, { from, to }) => sum + Math.min(to ?? end, end) - from, 0);

    return {
        start,
        end,
        outages: outages.reverse(),
        uptime: total > 0 ? (total - offline) / total : outages.length ? 0 : 1,
    };
}

export function renderConnectionHistory(container, events, options = {}) {
    const view = views.get(container) || { expanded: false, page: 1, cumulative: false };
    views.set(container, { ...view, events, options });
    draw(container);
}

export function handleConnectionHistoryClick(event) {
    const container = event.target?.closest?.("[data-connection-history]");
    const view = container && views.get(container);
    if (!view) return;

    const action = event.target.closest("[data-action]")?.dataset.action;
    if (action === "connectionToggle") {
        Object.assign(view, { expanded: !view.expanded, page: 1, cumulative: false });
    } else if (action === "connectionMore") {
        Object.assign(view, { page: view.page + 1, cumulative: true });
    } else {
        const totalPages = Math.max(1, Math.ceil(view.outageCount / PAGE_SIZE));
        const page = resolvePaginationPage(event, { page: view.page, total_pages: totalPages }, "connection");
        if (page === null) return;
        Object.assign(view, { page, cumulative: false });
    }
    draw(container);
}

function draw(container) {
    const view = views.get(container);
    // Quem nunca reportou uma ligação (o radar, por exemplo) não tem faixa: o «sem registo»
    // é só para quando os filtros os deixam de fora.
    const reported = view.options.reported ?? view.events.length > 0;
    container.closest("section")?.classList.toggle("d-none", !reported);
    const now = view.options.now ?? Date.now();
    const end = Math.min(now, view.options.end ?? now);
    const history = connectionOutages(view.events, { now, end });
    const totalPages = Math.max(1, Math.ceil(history.outages.length / PAGE_SIZE));
    view.outageCount = history.outages.length;
    view.page = Math.min(view.page, totalPages);

    // Isto passa por aqui a cada mensagem do stream: só se redesenha quando muda o que se vê,
    // e o minuto entra porque a faixa acaba em «agora».
    const signature = JSON.stringify([
        history.start,
        history.outages,
        Math.floor(end / MINUTE),
        view.expanded,
        view.page,
        view.cumulative,
    ]);
    if (view.signature === signature) return;
    view.signature = signature;

    container.dataset.connectionHistory = "";
    disposeTooltips(container);
    container.innerHTML = String(historyHtml(history, view, end === now, totalPages));
    // Os controlos do paginador saem do componente já como marcação, e entram como no
    // `renderPagination`.
    const pagination = { page: view.page, total_pages: totalPages };
    const pager = container.querySelector("[data-connection-pager]");
    if (pager) pager.innerHTML = paginationControls({ pagination, actionPrefix: "connection" });
    const more = container.querySelector("[data-connection-more]");
    if (more) more.innerHTML = loadMoreButton({ pagination, actionPrefix: "connection" });
    refreshTooltips(container);
}

function historyHtml(history, view, endsNow, totalPages) {
    if (history.start === null) {
        return html`
            ${header(html`Sem registo de ligações`, null)}
            <div class="connection-band connection-band-empty rounded"></div>`;
    }

    const span = Math.max(1, history.end - history.start);
    const segments = bandSegments(history).map(({ from, to, connected }) => {
        const left = ((from - history.start) / span) * 100;
        const width = ((to - from) / span) * 100;
        const until = to === history.end && endsNow ? "agora" : backAt(from, to);
        const title = `${connected ? "Ligado" : "Desligado"} ${shortWhen(from)} → ${until} · ${duration(from, to)}`;
        return html`<span class="${connected ? "connection-band-on" : "connection-band-gap"} position-absolute top-0 bottom-0"
            style="left:${left}%;width:${connected ? `${width}%` : `max(3px,${width}%)`}"
            data-bs-toggle="tooltip" data-bs-trigger="hover" data-bs-placement="top" data-bs-title="${title}"></span>`;
    });

    // Arredonda para baixo: uma quebra de segundos numa semana não pode ler-se «100 %».
    const uptime = (Math.floor(history.uptime * 1000) / 10).toLocaleString("pt-PT");
    const toggle = history.outages.length ? view : null;

    return html`
        ${header(html`<strong class="text-body fw-semibold">${uptime} %</strong> do tempo ligado`, toggle, history.outages.length)}
        <div class="connection-band position-relative rounded overflow-hidden">${segments}</div>
        <div class="connection-band-scale d-flex justify-content-between gap-2 mt-1 text-secondary tabular-nums">
            <span>${shortWhen(history.start)}</span>
            <span>${endsNow ? "agora" : shortWhen(history.end)}</span>
        </div>
        ${view.expanded && history.outages.length ? outageList(history, view, totalPages) : ""}`;
}

/** A faixa por troços, do mais antigo ao mais recente: ligado entre as quebras, e as quebras. */
function bandSegments(history) {
    const segments = [];
    let cursor = history.start;
    for (const { from, to } of [...history.outages].reverse()) {
        const until = Math.min(to ?? history.end, history.end);
        if (from > cursor) segments.push({ from: cursor, to: from, connected: true });
        segments.push({ from, to: until, connected: false });
        cursor = until;
    }
    if (cursor < history.end) segments.push({ from: cursor, to: history.end, connected: true });
    return segments;
}

function header(summary, view, count = 0) {
    const label = view?.expanded
        ? "Esconder quebras"
        : count === 1 ? "Ver a quebra" : `Ver as ${count} quebras`;
    const toggle = view
        ? html`<button type="button" class="connection-toggle btn btn-link text-decoration-none p-0 d-inline-flex align-items-center justify-content-center"
                data-action="connectionToggle" aria-expanded="${view.expanded ? "true" : "false"}"
                aria-label="${label}" title="${label}">
                <i class="fa-solid ${view.expanded ? "fa-chevron-up" : "fa-chevron-down"}"></i>
            </button>`
        : "";

    return html`
        <div class="d-flex flex-wrap justify-content-between align-items-center column-gap-2 mb-2">
            <div class="d-flex align-items-center gap-1">
                <span class="section-label text-nowrap">Ligações ao servidor</span>
                ${toggle}
            </div>
            <span class="connection-summary text-secondary text-nowrap tabular-nums">${summary}</span>
        </div>`;
}

function outageList(history, view, totalPages) {
    const longest = Math.max(
        ...history.outages.map(({ from, to }) => (to ?? history.end) - from),
    );
    const rows = pagedRows(history.outages, {
        page: view.page,
        pageSize: PAGE_SIZE,
        cumulative: view.cumulative,
    }).map(({ from, to }) => {
        const emphasis = history.outages.length > 1 && (to ?? history.end) - from === longest;
        return html`
            <div class="connection-outage tabular-nums" data-connection-outage>
                <span class="connection-outage-dot rounded-circle"></span>
                <span class="connection-outage-from"><span class="d-lg-none">Caiu </span>${shortWhen(from)}</span>
                <span class="connection-outage-to text-secondary"><span class="d-lg-none">Voltou </span>${backAt(from, to)}</span>
                <span class="connection-outage-span text-end${emphasis ? " fw-semibold text-danger" : ""}">${duration(from, to ?? history.end)}</span>
            </div>`;
    });

    const first = (view.page - 1) * PAGE_SIZE + 1;
    const last = Math.min(history.outages.length, view.page * PAGE_SIZE);

    return html`
        <div class="connection-outages mt-3">
            <div class="connection-outage connection-outage-head d-none d-lg-grid section-label">
                <span></span><span>Caiu</span><span>Voltou</span><span class="text-end">Fora</span>
            </div>
            ${rows}
        </div>
        ${totalPages > 1
            ? html`<div class="d-none d-lg-flex justify-content-between align-items-center gap-2 mt-2">
                <span class="small text-secondary tabular-nums">${first}–${last} de ${history.outages.length}</span>
                <nav aria-label="Paginação"><ul class="pagination pagination-sm mb-0 gap-1" data-connection-pager></ul></nav>
            </div>`
            : ""}
        <div class="d-grid d-lg-none" data-connection-more></div>`;
}

function shortWhen(time) {
    return new Date(time).toLocaleString("pt-PT", {
        day: "2-digit",
        month: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
}

/** O regresso no mesmo dia leva só a hora: a data está ao lado, na hora em que caiu. */
function backAt(from, to) {
    if (to === null) return "ainda desligado";
    const sameDay = new Date(from).toDateString() === new Date(to).toDateString();
    return sameDay
        ? new Date(to).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" })
        : shortWhen(to);
}

function duration(from, to) {
    const minutes = Math.round((to - from) / MINUTE);
    if (minutes < 1) return "< 1 min";
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return minutes % 60 ? `${hours} h ${minutes % 60} min` : `${hours} h`;
    return `${Math.floor(hours / 24)} d ${hours % 24} h`;
}
