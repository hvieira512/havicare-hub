import {
    deleteApiUser as apiDeleteApiUser,
    getApiUsers as apiGetApiUsers,
    saveApiUser as apiSaveApiUser,
} from "../api/index.js";
import { ensureLicensesLoaded } from "../licenses.js";
import { stateBadge } from "../components/state-badge.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { apiError, confirmDestructive, promptPassword, toast } from "../dialogs.js";
import { clearInvalid, markInvalid } from "../validation.js";
import { setSettingsNavCount } from "./shell.js";
import { renderPagination } from "../pagination.js";
import { editorOf, focusEditor } from "./row-editor.js";

/**
 * Os utilizadores da API, um por linha. A busca e os filtros de perfil e estado vêm do
 * descritor que o `GET /api/users` devolve, e quem estreita e pagina é o servidor.
 *
 * A password não é valor que se mostre, e por isso trocá-la é um verbo do menu da linha.
 * Criar continua a ser formulário: um utilizador novo precisa de password e de licença antes
 * de existir.
 */
let els;
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

/** O que está escolhido em cada filtro, por parâmetro do descritor. */
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

/** O perfil e o que ele alcança, em texto corrido por baixo do nome. */
function contextOf(user) {
    if (user.role === "hub_admin") {
        return "Administrador · todas as licenças";
    }
    const license = [user.company_name, user.license_id].filter(Boolean).join(" / ");

    return `${labelOf(user.role)} · ${license || "sem licença"}`;
}

/**
 * Uma linha por utilizador: o nome e o contexto à esquerda, o estado por palavra, e os verbos
 * num menu. Três botões só de ícone não se adivinhavam, e a pausa era o menos óbvio deles.
 */
export function apiUserRow(user) {
    const enabled = isEnabled(user);
    // A pastilha não encolhe: num telefone estreito é ela que diz o que a cor sozinha não diz.
    const badge = stateBadge(
        enabled ? "Ativo" : "Pausado",
        enabled ? "success" : "warning",
        "flex-shrink-0",
    );

    return html`
        <div class="list-group-item d-flex align-items-center gap-2">
            <div class="min-w-0 flex-grow-1">
                <span class="d-block text-truncate fw-semibold" title="${user.username}">${user.username}</span>
                <span class="d-block small text-secondary text-truncate">${contextOf(user)}</span>
            </div>
            ${raw(badge)}
            <div class="dropdown flex-shrink-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações de ${user.username}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button type="button" class="dropdown-item" data-action="changeApiUserPassword" data-id="${user.id}">Trocar palavra-passe</button></li>
                    <li><button type="button" class="dropdown-item" data-action="toggleApiUser" data-id="${user.id}">${enabled ? "Pausar acesso" : "Retomar acesso"}</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" class="dropdown-item text-danger" data-action="deleteApiUser" data-id="${user.id}">Eliminar utilizador</button></li>
                </ul>
            </div>
        </div>`;
}

/** As opções de um conjunto fechado, com a contagem que a faceta da resposta trouxe. */
function filterOptions(column, chosen, allLabel) {
    const options = (column.filter?.options ?? []).map(({ value, count }) => {
        const tally = count === null || count === undefined ? "" : ` (${count})`;
        const selected = String(value) === String(chosen) ? " selected" : "";

        return html`<option value="${value}"${raw(selected)}>${labelOf(value)}${tally}</option>`;
    });

    return html`<option value="">${allLabel}</option>${raw(options.join(""))}`;
}

/** A busca e os filtros que a grelha dava no cabeçalho, agora por cima da lista. */
export function apiUserFilterControls(columns, chosen = {}) {
    const controls = columns
        .map((column) => filterControl(column, chosen[column.field] ?? ""))
        .filter((control) => control !== "");

    return html`<div class="row g-2 mb-3">${raw(controls.join(""))}</div>`;
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
            <select class="form-select form-select-sm" data-filter="${column.field}" aria-label="Filtrar por ${spec.label}">${raw(filterOptions(column, chosen, spec.all))}</select>
        </div>`;
}

export function initSettingsApiUsers(context) {
    els = context.els;
}

const showError = (error) => toast("error", error.message);
const run = (work) => void work.catch(showError);

/** Recarregar é pedir outra vez a página que está à vista. */
const reload = () => loadSettingsApiUsersSection(currentPage);

async function fetchApiUsers(params) {
    const response = await apiGetApiUsers(params);
    if (response.error) {
        throw new Error(apiError(response));
    }

    users = response.data || [];
    // A contagem da faceta, e não das linhas desta página: o resumo fala da lista toda.
    adminCount = (response.filters?.counts?.role || [])
        .find((option) => option.value === "hub_admin")?.count ?? 0;

    return response;
}

/**
 * Só a última leitura pedida escreve a lista: duas teclas seguidas na busca põem dois pedidos
 * no ar e nada os cancela. É o mesmo contador do `stream.js` e do `list.js`.
 */
let generation = 0;

export async function loadSettingsApiUsersSection(page = 1) {
    state.settingsModal.sectionLoaded.apiUsers = true;
    currentPage = page;
    generation += 1;
    const current = generation;

    try {
        // As licenças são só do formulário de criar, e vêm da cache partilhada.
        const [response, loaded] = await Promise.all([
            fetchApiUsers({ page, sort: "username:asc", ...appliedFilters() }),
            ensureLicensesLoaded(),
        ]);
        if (current !== generation) {
            return;
        }
        licenses = loaded ?? [];
        renderApiUsers(response);
    } catch (error) {
        showError(error);
    }
}

const appliedFilters = () =>
    Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ""));

/** A barra de filtros por cima, a lista por baixo, dentro do contentor da secção. */
function mountList() {
    if (listEl !== null && els.apiUserGrid.contains(listEl)) {
        return;
    }
    els.apiUserGrid.innerHTML =
        "<div data-part=\"filters\"></div><div class=\"list-group\" data-part=\"rows\"></div>";
    filterEl = els.apiUserGrid.querySelector("[data-part=\"filters\"]");
    listEl = els.apiUserGrid.querySelector("[data-part=\"rows\"]");
}

/** A barra desenha-se uma vez: repintá-la a cada resposta tirava o cursor de dentro da busca. */
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
            : `${total} ${total === 1 ? "utilizador" : "utilizadores"}` +
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

/**
 * A licença que vai no corpo. Um admin manda em todas e por isso não fica preso a nenhuma;
 * o resto sai inteiro, porque a API declara `?int` e recusa a string em vez de a converter.
 */
export function licenseRefIdFor(role, value) {
    if (role === "hub_admin" || value === null || value === undefined || value === "") {
        return null;
    }

    return Number(value);
}

/** O `PUT` substitui o registo: vai a linha inteira, e não só o campo que mudou. */
async function saveUser(user, changes = {}) {
    const body = {
        username: user.username,
        role: user.role,
        enabled: isEnabled(user),
        ...changes,
    };
    body.licenseRefId = licenseRefIdFor(body.role, user.license_ref_id);

    const result = await apiSaveApiUser(user.id, body);
    if (result.error) {
        throw new Error(apiError(result));
    }
}

async function toggleApiUser(user) {
    await saveUser(user, { enabled: !isEnabled(user) });
    await reload();
}

async function changeApiUserPassword(user) {
    const { isConfirmed, value } = await promptPassword("Nova palavra-passe", user.username);
    if (!isConfirmed) {
        return;
    }
    await saveUser(user, { password: value });
    toast("success", "Palavra-passe alterada");
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

const licenseOptions = () =>
    "<option value=\"\">Selecionar licença</option>" +
    licenses
        .map((license) => html`<option value="${license.id}">${`${license.company_name || "-"} / ${license.license_id} — ${license.name || ""}`}</option>`)
        .join("");

const roleOptions = () =>
    ROLES.map((role) => html`<option value="${role}">${VALUE_LABELS[role]}</option>`).join("");

/** Criar pede password e licença, que a grelha não edita. Nasce ativo. */
function renderCreateForm(open) {
    if (!open) {
        els.apiUserCreateRow.innerHTML = "";
        return;
    }

    els.apiUserCreateRow.innerHTML = html`
        <div class="border rounded-3 p-3 mb-2 bg-body-tertiary" data-editor="apiUser">
            <div class="row g-2">
                <div class="col-12 col-md-3">
                    <label class="section-label" for="apiUserNewUsername">Utilizador</label>
                    <input type="text" class="form-control form-control-sm" id="apiUserNewUsername" data-field="username" autocomplete="off">
                </div>
                <div class="col-12 col-md-3">
                    <label class="section-label" for="apiUserNewPassword">Palavra-passe</label>
                    <input type="password" class="form-control form-control-sm" id="apiUserNewPassword" data-field="password" autocomplete="new-password">
                </div>
                <div class="col-12 col-md-2">
                    <label class="section-label" for="apiUserNewRole">Perfil</label>
                    <select class="form-select form-select-sm" id="apiUserNewRole" data-field="role">${raw(roleOptions())}</select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="section-label" for="apiUserNewLicense">Licença</label>
                    <select class="form-select form-select-sm" id="apiUserNewLicense" data-field="licenseRefId">${raw(licenseOptions())}</select>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelEdit">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" data-action="saveApiUserRow">Criar</button>
            </div>
        </div>`;

    focusEditor(els.apiUserCreateRow);
}

export function newApiUser() {
    renderCreateForm(true);
}

async function createApiUser(button) {
    const row = editorOf(button, "apiUser");
    if (!row) {
        return;
    }

    const { el, field } = row;
    const body = {
        username: row.value("username"),
        password: field("password").value,
        role: field("role").value,
        licenseRefId: licenseRefIdFor(field("role").value, row.value("licenseRefId")),
    };

    clearInvalid(el);
    if (!body.username) {
        markInvalid(field("username"), "Utilizador é obrigatório");
    }
    if (!body.password.trim()) {
        markInvalid(field("password"), "A palavra-passe é obrigatória para um utilizador novo");
    }
    if (body.role === "license_client" && !body.licenseRefId) {
        markInvalid(field("licenseRefId"), "Licença é obrigatória para clientes");
    }
    if (el.querySelector(".is-invalid")) {
        return;
    }

    const result = await apiSaveApiUser("", body);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }

    renderCreateForm(false);
    await reload();
}

/** Os cliques da secção: o formulário de criar, e os verbos do menu de cada linha. */
export function handleApiUserListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) {
        return;
    }
    const user = users.find((row) => String(row.id) === button.dataset.id);
    const actions = {
        cancelEdit: () => renderCreateForm(false),
        saveApiUserRow: () => run(createApiUser(button)),
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

/** A busca estreita a lista enquanto se escreve. */
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
