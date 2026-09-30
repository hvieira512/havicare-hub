import { getDenylist, unblockDevice } from "../api/index.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { ago } from "../format.js";
import { apiError, toast } from "../dialogs.js";
import { emptyPanel } from "../components/empty-panel.js";
import { setSettingsNavCount } from "./shell.js";

/**
 * O separador da denylist: os aparelhos estranhos bloqueados de propósito. Bloquear é gesto da
 * notificação; aqui só se vê a lista e se desbloqueia.
 */
let els;
let current = [];

export function initSettingsDenylist(context) {
    els = context.els;
}

export async function loadSettingsDenylistSection() {
    const result = await getDenylist();
    if (result?.error) {
        els.denylistListBody.innerHTML =
            "<div class=\"text-center text-danger small p-4\">Não foi possível carregar a lista de bloqueados.</div>";
        return;
    }
    current = Array.isArray(result?.data) ? result.data : [];
    state.settingsModal.sectionLoaded.denylist = true;
    renderDenylistSection();
}

function denylistRow(entry) {
    const meta = [entry.protocol, entry.note].filter(Boolean).join(" · ");
    const metaLine = meta === ""
        ? ""
        : html`<span class="d-block small text-secondary text-break">${meta}</span>`;
    const who = [entry.created_by, entry.created_at ? ago(entry.created_at) : ""]
        .filter(Boolean).join(" · ");
    const whoLine = who === ""
        ? ""
        : html`<span class="d-block small text-secondary">${who}</span>`;
    // Num telefone o botão desce para baixo do texto: uma identidade de quinze dígitos em
    // monoespaçada e um botão de 96px não cabem os dois numa calha de 300px.
    return html`
        <div class="list-group-item d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-2">
            <div class="min-w-0 flex-grow-1">
                <span class="d-block font-monospace text-truncate" title="${entry.identity}">${entry.identity}</span>
                ${raw(metaLine)}
                ${raw(whoLine)}
            </div>
            <button class="btn btn-outline-secondary btn-sm flex-shrink-0" data-action="unblock" data-id="${entry.identity}">Desbloquear</button>
        </div>`;
}

function renderDenylistSection() {
    const total = current.length;
    setSettingsNavCount("Denylist", total);

    // Sem bloqueados, o vazio é um estado só: o título di-lo e a frase por baixo explica de
    // onde vêm os bloqueios, que é o único separador onde não se acrescenta nada daqui.
    if (els.denylistTabTitle) {
        els.denylistTabTitle.textContent = total === 0
            ? "Nenhum aparelho bloqueado"
            : "Aparelhos bloqueados";
    }
    if (els.denylistTabSummary) {
        els.denylistTabSummary.textContent = total === 0
            ? ""
            : `${total} ${total === 1 ? "aparelho bloqueado" : "aparelhos bloqueados"}`;
    }

    els.denylistListBody.innerHTML = total === 0
        ? emptyPanel("Um aparelho bloqueia-se a partir da notificação de «Dispositivo não autorizado».")
        : html`<div class="list-group">${raw(current.map(denylistRow).join(""))}</div>`;
}

async function unblock(identity) {
    const result = await unblockDevice(identity);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    current = current.filter((entry) => String(entry.identity) !== String(identity));
    renderDenylistSection();
}

/** Os cliques da lista: só o «Desbloquear» de cada linha. */
export function handleDenylistListClick(event) {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.dataset.action === "unblock") {
        void unblock(button.dataset.id);
    }
}
