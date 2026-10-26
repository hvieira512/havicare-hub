import {
    deleteApiUser as apiDeleteApiUser,
    getApiUsers as apiGetApiUsers,
    saveApiUser as apiSaveApiUser,
} from "../api/index.js";
import { ensureLicensesLoaded } from "../core/licenses.js";
import { stateBadge } from "../components/state-badge.js";
import { rowActionsMenu } from "../components/row-actions-menu.js";
import { state } from "../core/state.js";
import { html } from "../core/html.js";
import { apiError, confirmDestructive, promptPassword, reportApiError, toast } from "../core/dialogs.js";
import { clearInvalid } from "../core/validation.js";
import { handleSettingsPaginationClick, setSettingsNavCount } from "./shell.js";
import { renderPagination } from "../core/pagination.js";
import { editorOf, focusEditor } from "./row-editor.js";
import { countLabel } from "../core/i18n.js";
import { els, whileBusy } from "../core/dom.js";

/** Os utilizadores da API; a busca, os filtros e a paginação são do servidor. */
let licenses = [];
let users = [];
let adminCount = 0;
let currentPage = 1;
let filterEl = null;
let listEl = null;

/** O que cada filtro do descritor mostra. Um campo que não esteja aqui não gera controlo. */
const FILTER_CONTROLS = {
    username: { label: "Procurar utilizador" },
    role: { label: "perfil", all: "Todos os perfis" },
    enabled: { label: "estado", all: "Todos os estados" },
};

const filters = { username: "", role: "", enabled: "" };

const VALUE_LABELS = {
    hub_admin: "Administrador",
    license_client: "Cliente",
    1: "Ativo",
    0: "Pausado",
};

/** O primeiro é o que um utilizador novo traz escolhido. */
const ROLES = ["license_client", "hub_admin"];

const isEnabled = (user) => Number(user.enabled) === 1;

const labelOf = (value) => VALUE_LABELS[value] ?? String(value ?? "");

function contextOf(user) {
    if (user.role === "hub_admin") {
        return "Administrador · todas as licenças";
    }
    const license = [user.companyName, user.licenseId].filter(Boolean).join(" / ");

    return `${labelOf(user.role)} · ${license || "sem licença"}`;
}

function apiUserRow(user) {
    const enabled = isEnabled(user);
    const badge = stateBadge(
        enabled ? "Ativo" : "Pausado",
        enabled ? "success" : "warning",
        "flex-shrink-0",
    );
    const id = html`data-id="${user.id}"`;
    const menu = rowActionsMenu(`Ações de ${user.username}`, [
        { action: "editApiUser", attrs: id, label: "Editar utilizador" },
        { action: "changeApiUserPassword", attrs: id, label: "Trocar palavra-passe" },
        { action: "toggleApiUser", attrs: id, label: enabled ? "Pausar acesso" : "Retomar acesso" },
        "divider",
        { action: "deleteApiUser", attrs: id, label: "Eliminar utilizador", danger: true },
    ]);

    return html`
        <div class="list-group-item d-flex align-items-center gap-2">
            <div class="min-w-0 flex-grow-1">
                <span class="d-block text-truncate fw-semibold" title="${user.username}">${user.username}</span>
                <span class="d-block small text-secondary text-truncate">${contextOf(user)}</span>
            </div>
            ${badge}
            ${menu}
        </div>`;
}

/** As opções de um conjunto fechado, com a contagem que a faceta da resposta trouxe. */
function filterOptions(column, chosen, allLabel) {
    const options = (column.filter?.options ?? []).map(({ value, count }) => {
        const tally = count === null || count === undefined ? "" : ` (${count})`;
        const selected = String(value) === String(chosen) ? " selected" : "";

        return html`<option value="${value}"${selected}>${labelOf(value)}${tally}</option>`;
    });

    return html`<option value="">${allLabel}</option>${options}`;
}

export function apiUserFilterControls(columns, chosen = {}) {
    const controls = columns
        .map((column) => filterControl(column, chosen[column.field] ?? ""))
        .filter((control) => control !== "");

    return html`<div class="row g-2 mb-3">${controls}</div>`;
}

function filterControl(column, chosen) {
    const spec = FILTER_CONTROLS[column.field];
    if (!spec) {
        return "";
    }

    if (column.filter?.type === "text") {
        return html`
            <div class="col-12 col-sm">
                <input type="search" class="form-control form-control-sm" data-filter="${column.field}" value="${chosen}" placeholder="${spec.label}" aria-label="${spec.label}" autocomplete="off">
            </div>`;
    }

    if (column.filter?.type !== "select") {
        return "";
    }

    return html`
        <div class="col-6 col-sm-auto">
            <select class="form-select form-select-sm" data-filter="${column.field}" aria-label="Filtrar por ${spec.label}">${filterOptions(column, chosen, spec.all)}</select>
        </div>`;
}

export function initSettingsApiUsers() {
    els.newApiUserBtn?.addEventListener("click", newApiUser);
    // No invólucro e não na lista: apanha o formulário de criar, os menus das linhas e os filtros.
    els.apiUserList.addEventListener("click", handleApiUserListClick);
    els.apiUserList.addEventListener("change", handleApiUserListChange);
    els.apiUserList.addEventListener("input", handleApiUserListInput);
    els.settingsApiUsersPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, state.settingsModal.apiUsersPagination, "settingsApiUsersPage", loadSettingsApiUsersSection),
    );
}

const showError = (error) => toast("error", error.message);
const run = (work) => void work.catch(showError);

const reload = () => loadSettingsApiUsersSection(currentPage);

/** Só a última leitura pedida escreve a lista: duas teclas seguidas põem dois pedidos no ar. */
let generation = 0;

export async function loadSettingsApiUsersSection(page = 1) {
    state.settingsModal.sectionLoaded.apiUsers = true;
    currentPage = page;
    generation += 1;
    const current = generation;

    // As licenças são só do formulário de criar, e vêm da cache partilhada.
    const [response, loaded] = await Promise.all([
        apiGetApiUsers({ page, sort: "username:asc", ...appliedFilters() }),
        ensureLicensesLoaded(),
    ]);
    if (current !== generation) {
        return;
    }
    if (response.error) {
        toast("error", apiError(response));
        return;
    }

    users = response.data || [];
    // A contagem da faceta, e não das linhas desta página: o resumo fala da lista toda.
    adminCount = (response.filters?.counts?.role || [])
        .find((option) => option.value === "hub_admin")?.count ?? 0;
    licenses = loaded ?? [];
    renderApiUsers(response);
}

const appliedFilters = () =>
    Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ""));

function mountList() {
    if (listEl !== null && els.apiUserGrid.contains(listEl)) {
        return;
    }
    els.apiUserGrid.innerHTML =
        "<div data-part=\"filters\"></div><div class=\"list-group\" data-part=\"rows\"></div>";
    filterEl = els.apiUserGrid.querySelector("[data-part=\"filters\"]");
    listEl = els.apiUserGrid.querySelector("[data-part=\"rows\"]");
}

/** A barra desenha-se uma vez, para não tirar o cursor de dentro da busca. */
function renderFilterBar(columns) {
    if (filterEl.childElementCount === 0) {
        filterEl.innerHTML = apiUserFilterControls(columns, filters);
        return;
    }

    // As contagens mudam a cada filtro; o que está escolhido fica.
    for (const column of columns) {
        const select = filterEl.querySelector(`select[data-filter="${column.field}"]`);
        if (select) {
            select.innerHTML = filterOptions(
                column,
                filters[column.field] ?? "",
                FILTER_CONTROLS[column.field]?.all ?? "Todos",
            );
        }
    }
}

function renderApiUsers(response) {
    mountList();
    renderFilterBar(response.columns ?? []);

    listEl.innerHTML = users.length === 0
        ? "<div class=\"text-center text-secondary small p-4\">Nenhum utilizador para este filtro.</div>"
        : users.map(apiUserRow).join("");

    renderApiUsersPage(response.pagination || {});
}

function renderApiUsersPage(pagination) {
    state.settingsModal.apiUsersPagination = pagination;

    const total = pagination.total ?? 0;
    setSettingsNavCount("ApiUsers", total);
    if (els.apiUsersTabSummary) {
        els.apiUsersTabSummary.textContent = total === 0
            ? "Nenhum utilizador"
            : `${countLabel(total, "utilizador", "utilizadores")}` +
                (adminCount ? ` · ${adminCount} com acesso a todas as licenças` : "");
    }

    renderPagination({
        pagination,
        rootEl: els.settingsApiUsersPagination,
        summaryEl: els.settingsApiUsersPaginationSummary,
        controlsEl: els.settingsApiUsersPaginationControls,
        actionPrefix: "settingsApiUsersPage",
    });
}

/** Um admin não fica preso a licença nenhuma; o resto vai inteiro, porque a API recusa a string. */
export function licenseRefIdFor(role, value) {
    if (role === "hub_admin" || value === null || value === undefined || value === "") {
        return null;
    }

    return Number(value);
}

/** O `PUT` substitui o registo: vai a linha inteira. Um erro sai num aviso, e devolve `false`. */
async function saveUser(user, changes = {}) {
    const body = {
        username: user.username,
        role: user.role,
        enabled: isEnabled(user),
        ...changes,
    };
    body.licenseRefId = licenseRefIdFor(body.role, user.licenseRefId);

    const result = await apiSaveApiUser(user.id, body);
    if (result.error) {
        toast("error", apiError(result));
        return false;
    }
    return true;
}

async function toggleApiUser(user) {
    if (await saveUser(user, { enabled: !isEnabled(user) })) await reload();
}

async function changeApiUserPassword(user) {
    const { isConfirmed, value } = await promptPassword("Nova palavra-passe", user.username);
    if (!isConfirmed) {
        return;
    }
    if (await saveUser(user, { password: value })) toast("success", "Palavra-passe alterada");
}

export async function deleteApiUser(user) {
    const { isConfirmed } = await confirmDestructive(
        `Apagar o utilizador ${user.username}?`,
        "Perde o acesso à API assim que for apagado.",
    );
    if (!isConfirmed) {
        return;
    }
    const result = await apiDeleteApiUser(user.id);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    await reload();
}

const licenseOptions = (licenseList, chosen) =>
    html`<option value="">Selecionar licença</option>${licenseList.map((license) => html`<option value="${license.id}"${String(license.id) === String(chosen ?? "") ? " selected" : ""}>${`${license.companyName || "-"} / ${license.licenseId} — ${license.name || ""}`}</option>`)}`;

const roleOptions = (chosen) =>
    ROLES.map((role) => html`<option value="${role}"${role === chosen ? " selected" : ""}>${VALUE_LABELS[role]}</option>`);

/** O formulário de um utilizador; sem `user` é o de criar, o único que pede password. */
export function apiUserForm(user, licenseList) {
    const role = user?.role ?? ROLES[0];
    const passwordField = user !== null && user !== undefined
        ? ""
        : html`
                <div class="col-12 col-md-3">
                    <label class="section-label" for="apiUserNewPassword">Palavra-passe</label>
                    <input type="password" class="form-control form-control-sm" id="apiUserNewPassword" data-field="password" autocomplete="new-password">
                </div>`;

    // O estado viaja com o editor: a linha pode já não estar na página carregada.
    const enabledAttr = user ? html` data-enabled="${isEnabled(user) ? "1" : "0"}"` : "";

    return html`
        <div class="border rounded-3 p-3 mb-2 bg-body-tertiary" data-editor="apiUser" data-id="${user?.id ?? ""}"${enabledAttr}>
            <div class="row g-2">
                <div class="col-12 col-md-3">
                    <label class="section-label" for="apiUserNewUsername">Utilizador</label>
                    <input type="text" class="form-control form-control-sm" id="apiUserNewUsername" data-field="username" value="${user?.username ?? ""}" autocomplete="off">
                </div>${passwordField}
                <div class="col-12 col-md-2">
                    <label class="section-label" for="apiUserNewRole">Perfil</label>
                    <select class="form-select form-select-sm" id="apiUserNewRole" data-field="role">${roleOptions(role)}</select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="section-label" for="apiUserNewLicense">Licença</label>
                    <select class="form-select form-select-sm" id="apiUserNewLicense" data-field="licenseRefId"${role === "hub_admin" ? " disabled" : ""}>${licenseOptions(licenseList, user?.licenseRefId)}</select>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelEdit">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" data-action="saveApiUserRow">${user ? "Guardar" : "Criar"}</button>
            </div>
        </div>`;
}

function openUserForm(user) {
    els.apiUserCreateRow.innerHTML = apiUserForm(user, licenses);
    focusEditor(els.apiUserCreateRow);
}

const closeUserForm = () => {
    els.apiUserCreateRow.innerHTML = "";
};

export function newApiUser() {
    openUserForm(null);
}

async function saveApiUserRow(button) {
    const row = editorOf(button, "apiUser");
    if (!row) {
        return;
    }

    const { el, field, id } = row;
    const body = {
        username: row.value("username"),
        role: field("role").value,
        licenseRefId: licenseRefIdFor(field("role").value, row.value("licenseRefId")),
    };
    if (id === "") {
        body.password = field("password").value;
    } else {
        // O estado não está no formulário: quem o muda é o verbo de pausar.
        body.enabled = el.dataset.enabled === "1";
    }

    clearInvalid(el);
    const result = await apiSaveApiUser(id, body);
    if (result.error) {
        reportApiError(result, field);
        return;
    }

    closeUserForm();
    await reload();
}

export function handleApiUserListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) {
        return;
    }
    const user = users.find((row) => String(row.id) === button.dataset.id);
    const actions = {
        cancelEdit: closeUserForm,
        saveApiUserRow: () => run(whileBusy(button, () => saveApiUserRow(button))),
        editApiUser: () => user && openUserForm(user),
        changeApiUserPassword: () => user && run(changeApiUserPassword(user)),
        toggleApiUser: () => user && run(toggleApiUser(user)),
        deleteApiUser: () => user && run(deleteApiUser(user)),
    };
    actions[button.dataset.action]?.();
}

/** Um filtro novo volta à primeira página: a 3 da lista nova não tem as mesmas linhas. */
function applyFilter(control) {
    filters[control.dataset.filter] = control.value;
    run(loadSettingsApiUsersSection(1));
}

export function handleApiUserListInput(event) {
    const search = event.target.closest("input[data-filter]");
    if (search) {
        applyFilter(search);
    }
}

/** O perfil de admin manda em todas as licenças, por isso a escolha de uma não se aplica. */
export function handleApiUserListChange(event) {
    const filter = event.target.closest("select[data-filter]");
    if (filter) {
        applyFilter(filter);
        return;
    }

    const select = event.target.closest("[data-field=\"role\"]");
    if (!select) {
        return;
    }
    const license = editorOf(select, "apiUser")?.field("licenseRefId");
    if (!license) {
        return;
    }
    license.disabled = select.value === "hub_admin";
    if (license.disabled) {
        license.value = "";
    }
}
