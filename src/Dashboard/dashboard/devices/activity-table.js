import { html, raw } from "../html.js";
import { emptyPanel } from "../widgets.js";

/**
 * A tabela genérica de atividade -- a de telemetria e a de pedidos usam-na igual. Recebe as
 * linhas já prontas (o descritor de cada uma), pinta-as, e trata da gaveta que abre por baixo
 * e da posição de rolagem entre mensagens do stream. Não sabe nada do detalhe do dispositivo.
 */

/**
 * As linhas abertas, por chave do registo e não por posição: a lista redesenha-se a cada
 * mensagem do stream, e um índice apontaria para outra linha assim que chegasse um evento.
 */
const openActivityRows = new Set();

/** A página que cada lista tinha da última vez, para se saber quando o leitor mudou de página. */
const lastRenderedPage = new Map();

export function activityTable(rootEl, rows, emptyText, idPrefix, page = 1) {
    // A lista rola por dentro e a posição repõe-se, senão cada mensagem do stream atirava
    // para o topo uma lista que estava a ser lida. Mudar de página é o contrário.
    const pageChanged = lastRenderedPage.get(idPrefix) !== page;
    lastRenderedPage.set(idPrefix, page);
    const scrollTop = pageChanged ? 0 : rootEl.scrollTop;
    rootEl.innerHTML = rows.length
        ? html`<table class="table table-sm align-middle mb-0 telemetry-table">
            <tbody>${raw(rows.map((row, index) => activityRow(row, `${idPrefix}${index}`)).join(""))}</tbody>
           </table>`
        : emptyPanel(emptyText);
    rootEl.scrollTop = scrollTop;
}

/** Abre ou fecha a linha carregada. Devolve `true` quando tratou do clique. */
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

    // A gaveta é a linha logo a seguir, e é assim que se procura: por `getElementById` só se
    // acha o que já está pendurado no documento, e as duas listas desenham-se antes disso.
    const panel = row.nextElementSibling;
    const key = row.dataset.rowKey || "";
    const open = row.getAttribute("aria-expanded") !== "true";
    row.setAttribute("aria-expanded", open ? "true" : "false");
    panel?.classList.toggle("d-none", !open);
    if (open) {
        openActivityRows.add(key);
    } else {
        openActivityRows.delete(key);
    }

    return true;
}

/**
 * Uma linha da lista de actividade, e a linha escondida que a abre.
 *
 * Todas medem o mesmo, e os detalhes cortam-se numa linha: sem isso a altura de uma página
 * dependia dos tipos que lhe calhassem. O que fica de fora vê-se abrindo a linha, e não numa
 * tooltip, que por teclado e em telemóvel não existe.
 */
function activityRow({
    icon,
    tone = "",
    name,
    nameTitle = "",
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
}, panelId) {
    const subLine = sub
        ? html`<span class="telemetry-row-details text-secondary lh-sm fw-normal d-block text-truncate"${raw(subTitle ? html` title="${subTitle}"` : "")}>${raw(sub)}</span>`
        : "";
    // As pastilhas não se cortam a meio: já vêm limitadas na origem, e o que sobra do lado
    // direito esconde-se. O texto corta-se com reticências, como o nome na coluna ao lado.
    const detailClass = detailKind === "chips"
        ? "telemetry-row-details text-secondary lh-sm d-flex gap-1 overflow-hidden"
        : "telemetry-row-details text-secondary lh-sm d-block text-truncate";
    const detailLine = detail
        ? html`<span class="${detailClass}"${raw(detailTitle ? html` title="${detailTitle}"` : "")}>${raw(detail)}</span>`
        : "";

    const openable = expanded !== "";
    const isOpen = openable && openActivityRows.has(key);
    const rowAttrs = openable
        ? raw(html` class="telemetry-row-openable" role="button" tabindex="0" aria-expanded="${isOpen ? "true" : "false"}" aria-controls="${panelId}" data-row-toggle="${panelId}" data-row-key="${key}"`)
        : raw("");
    const caret = openable
        ? raw(html`<i class="fa-solid fa-chevron-down telemetry-row-caret text-secondary ms-2" aria-hidden="true"></i>`)
        : raw("");
    const panel = openable
        ? html`<tr id="${panelId}" class="telemetry-row-panel${isOpen ? "" : " d-none"}">
            <td colspan="4">${expanded}</td>
           </tr>`
        : "";

    return html`
        <tr${rowAttrs}>
        <td>
            <span class="telemetry-row-icon d-flex align-items-center justify-content-center flex-shrink-0 rounded-3${tone ? ` telemetry-card-tone-${tone}` : ""}">
                <i class="fa-solid ${icon}"></i>
            </span>
        </td>
        <td class="fw-medium">
            <span class="telemetry-row-stack d-flex flex-column justify-content-center min-w-0">
                <span class="d-block text-truncate" title="${nameTitle || name}">${name}</span>
                ${raw(subLine)}
            </span>
        </td>
        <td class="tabular-nums"${raw(valueTitle ? html` title="${valueTitle}"` : "")}>
            <span class="telemetry-row-stack d-flex flex-column justify-content-center min-w-0">
                <span class="d-block text-truncate">${raw(value)}</span>
                ${raw(detailLine)}
            </span>
        </td>
        <td class="text-end text-nowrap tabular-nums text-secondary" title="${timeTitle}">${time}${caret}</td>
        </tr>${raw(panel)}`;
}
