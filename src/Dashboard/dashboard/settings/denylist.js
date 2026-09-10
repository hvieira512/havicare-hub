import { getDenylist, unblockDevice } from "../api/index.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { ago } from "../format.js";
import { apiError, toast } from "../dialogs.js";
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
    return html`
        <div class="tree-row position-relative d-flex align-items-center justify-content-between">
            <div class="min-w-0">
                <span class="d-block font-monospace text-break">${entry.identity}</span>
                ${raw(metaLine)}
                ${raw(whoLine)}
            </div>
            <button class="btn btn-outline-secondary btn-sm flex-shrink-0" data-action="unblock" data-id="${entry.identity}" title="Desbloquear">Desbloquear</button>
        </div>`;
}

function renderDenylistSection() {
    const total = current.length;
    if (els.denylistTabSummary) {
        els.denylistTabSummary.textContent =
            `${total} ${total === 1 ? "aparelho bloqueado" : "aparelhos bloqueados"}`;
    }
    setSettingsNavCount("Denylist", total);

    // O vazio diz de onde vêm os bloqueios em vez de repetir que não há nenhum: é o único
    // separador onde não se faz nada, e era o único que não o explicava.
    els.denylistListBody.innerHTML = total === 0
        ? "<div class=\"text-center text-secondary small p-4\">Um aparelho bloqueia-se a partir da notificação de «Dispositivo não autorizado». Os bloqueados aparecem aqui.</div>"
        : current.map(denylistRow).join("");
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
