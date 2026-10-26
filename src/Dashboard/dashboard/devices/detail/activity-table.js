import { html } from "../../core/html.js";
import { emptyPanel } from "../../components/empty-panel.js";
import { renderInto } from "../../core/dom.js";
import { dayKey, dayLabel } from "../../core/format.js";

/** Tabela de atividade da telemetria e dos pedidos: pinta as linhas e trata da gaveta. */

/** Por tabela e por chave do registo, e não por posição: a lista redesenha-se a cada mensagem do stream. */
const openActivityRows = new WeakMap();

const openRowsOf = (rootEl) => {
    if (!openActivityRows.has(rootEl)) openActivityRows.set(rootEl, new Set());
    return openActivityRows.get(rootEl);
};

/** Com `table-layout: fixed` as larguras saem da primeira linha, que é um cabeçalho com `colspan`. */
const COLGROUP = html`<colgroup>
    <col class="telemetry-col-icon">
    <col>
    <col class="telemetry-col-value">
    <col class="telemetry-col-time">
</colgroup>`;

export function activityTable(rootEl, rows, emptyText, idPrefix) {
    // A chave do registo sobrevive ao redesenho, e é por ela que o foco volta.
    const focusedKey = rootEl.contains(document.activeElement)
        ? document.activeElement.closest("[data-row-key]")?.dataset.rowKey
        : null;

    // Só se lembram as abertas que ainda estão na lista.
    const open = openRowsOf(rootEl);
    const present = new Set(rows.map((row) => row.key));
    open.forEach((key) => present.has(key) || open.delete(key));

    const written = renderInto(rootEl, rows.length
        ? html`<table class="table table-sm align-middle mb-0 telemetry-table">
            ${COLGROUP}
            <tbody>${groupedByDay(rows, idPrefix, open)}</tbody>
           </table>`
        : emptyPanel(emptyText));

    if (written && focusedKey) {
        rootEl.querySelector(`[data-row-key="${CSS.escape(focusedKey)}"]`)?.focus();
    }
}

/** As linhas com um cabeçalho por dia; uma linha sem instante não abre grupo nenhum. */
function groupedByDay(rows, idPrefix, open) {
    const counts = new Map();
    rows.forEach((row) => {
        const key = dayKey(row.at);
        if (key) counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    let openDay = null;
    return rows.map((row, index) => {
        const key = dayKey(row.at);
        const header = key && key !== openDay
            ? dayHeader(dayLabel(row.at), counts.get(key) ?? 0)
            : "";
        if (key) openDay = key;
        return html`${header}${activityRow(row, `${idPrefix}${index}`, open)}`;
    });
}

function dayHeader(label, count) {
    return html`
        <tr class="telemetry-day" data-day-header>
            <td colspan="4" class="text-uppercase small text-secondary fw-semibold">${label}<span class="telemetry-day-count ms-2 fw-normal">${String(count)}</span></td>
        </tr>`;
}

/** Devolve `true` quando tratou do clique. */
export function toggleActivityRow(event) {
    const row = event.target.closest("[data-row-toggle]");
    if (!row) {
        return false;
    }
    if (event.type === "keydown" && event.key !== "Enter" && event.key !== " ") {
        return false;
    }
    if (event.type === "keydown") {
        // O espaço rola a página se o deixarmos passar.
        event.preventDefault();
    }

    // A gaveta é a linha seguinte: as listas desenham-se antes de entrarem no documento.
    const panel = row.nextElementSibling;
    const key = row.dataset.rowKey || "";
    const open = row.getAttribute("aria-expanded") !== "true";
    row.setAttribute("aria-expanded", open ? "true" : "false");
    panel?.classList.toggle("d-none", !open);
    const openRows = openRowsOf(row.closest("table")?.parentElement ?? row);
    if (open) {
        openRows.add(key);
    } else {
        openRows.delete(key);
    }

    return true;
}

/** Uma linha e a gaveta que ela abre: os detalhes cortam-se numa linha e o resto vê-se nela. */
function activityRow({
    icon,
    badge = "",
    tone = "",
    name,
    sub = "",
    subTitle = "",
    value = "",
    valueTitle = "",
    detail = "",
    detailKind = "text",
    detailTitle = "",
    expanded = "",
    key = "",
    time,
    timeTitle = "",
}, panelId, openRows) {
    // Abaixo do `lg` a hora desce para baixo do nome, para o valor caber na linha.
    const stackedTime = html`<span class="d-lg-none">${sub ? " · " : ""}${time}</span>`;
    const subClass = sub ? "telemetry-row-details" : "telemetry-row-time d-lg-none";
    const subLine = html`<span class="${subClass} text-secondary lh-sm fw-normal d-block text-truncate"${subTitle ? html` title="${subTitle}"` : ""}>${sub}${stackedTime}</span>`;
    // As pastilhas não se cortam a meio, escondem-se; o texto corta-se com reticências.
    const detailClass = detailKind === "chips"
        ? "telemetry-row-details text-secondary lh-sm d-flex gap-1 overflow-hidden"
        : "telemetry-row-details text-secondary lh-sm d-block text-truncate";
    const detailLine = detail
        ? html`<span class="${detailClass}"${detailTitle ? html` title="${detailTitle}"` : ""}>${detail}</span>`
        : "";

    const openable = expanded !== "";
    const isOpen = openable && openRows.has(key);
    const rowAttrs = openable
        ? html` class="telemetry-row-openable" role="button" tabindex="0" aria-expanded="${isOpen ? "true" : "false"}" aria-controls="${panelId}" data-row-toggle="${panelId}" data-row-key="${key}"`
        : "";
    const caret = openable
        ? html`<i class="fa-solid fa-chevron-down telemetry-row-caret text-secondary ms-2" aria-hidden="true"></i>`
        : "";
    // Uma linha por campo; o texto chega simples e escapa-se aqui.
    const panel = openable
        ? html`<tr id="${panelId}" class="telemetry-row-panel${isOpen ? "" : " d-none"}">
            <td colspan="4">${String(expanded).split("\n").map((line) => html`<div>${line}</div>`)}</td>
           </tr>`
        : "";

    return html`
        <tr${rowAttrs}>
        <td>
            <span class="telemetry-row-icon position-relative d-flex align-items-center justify-content-center flex-shrink-0 rounded-3${tone ? ` telemetry-card-tone-${tone}` : ""}">
                <i class="fa-solid ${icon}"></i>
                ${badge ? html`<span class="telemetry-card-badge d-flex align-items-center justify-content-center rounded-circle"><i class="fa-solid ${badge}"></i></span>` : ""}
            </span>
        </td>
        <td class="fw-medium">
            <span class="telemetry-row-stack d-flex flex-column justify-content-center min-w-0">
                <span class="d-block text-truncate" title="${name}">${name}</span>
                ${subLine}
            </span>
        </td>
        <td class="tabular-nums"${valueTitle ? html` title="${valueTitle}"` : ""}>
            <span class="telemetry-row-stack d-flex flex-column justify-content-center min-w-0">
                <span class="d-block text-truncate">${value}</span>
                ${detailLine}
            </span>
        </td>
        <td class="text-end text-nowrap tabular-nums text-secondary" title="${timeTitle}"><span class="d-none d-lg-inline">${time}</span>${caret}</td>
        </tr>${panel}`;
}
