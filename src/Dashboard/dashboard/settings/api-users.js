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
import { renderPagination, resolvePaginationPage } from "../pagination.js";
import { editorOf, focusEditor } from "./row-editor.js";
import { createGrid } from "../grid.js";

/**
 * Os utilizadores da API, numa grelha. As colunas, o que se ordena, o que se filtra e o que
 * se edita vêm do descritor que o `GET /api/users` devolve.
 *
 * Edita-se por célula. A password não é coluna -- não é valor que se mostre --, e por isso é
 * uma acção da linha. Criar continua a ser formulário: um utilizador novo precisa de
 * password e de licença antes de existir, e isso não cabe numa célula.
 */
let els;
let grid = null;
let licenses = [];
let users = [];
let adminCount = 0;

const COLUMN_TITLES = {
    username: "Utilizador",
    role: "Perfil",
    company_name: "Empresa",
    license_id: "Licença",
    enabled: "Estado",
};

const VALUE_LABELS = {
    hub_admin: "Administrador",
    license_client: "Cliente",
    1: "Ativo",
    0: "Inativo",
};

/** O primeiro é o que um utilizador novo traz escolhido. */
const ROLES = ["license_client", "hub_admin"];

const isEnabled = (user) => Number(user.enabled) === 1;

const labelOf = (value) => VALUE_LABELS[value] ?? String(value ?? "");

/** Um conjunto fechado escolhe-se de uma lista; sem isto o editor era caixa de texto. */
const closedSet = (values) => ({
    cellEditor: "agSelectCellEditor",
    cellEditorParams: { values },
    valueFormatter: (params) => labelOf(params.value),
});

/**
 * O perfil, na pastilha: o escudo é quem manda em todas as licenças, o edifício é quem tem
 * uma. O `secondary` fica de fora porque aqui lê-se como inativo, e um cliente não é isso.
 */
const ROLE_BADGES = {
    hub_admin: { tone: "primary", icon: "fa-shield-halved" },
    license_client: { tone: "info", icon: "fa-building" },
};

function roleCell(params) {
    const badge = ROLE_BADGES[params.value];

    return stateBadge(labelOf(params.value), badge?.tone, { icon: badge?.icon });
}

/**
 * A pastilha só veste a célula em repouso. O `valueFormatter` que vem do `closedSet` desenha
 * as opções do `agSelectCellEditor`, e marcação dentro de um `<option>` não se desenha.
 *
 * A largura mínima é maior do que a das outras colunas porque uma pastilha não encolhe: com
 * os 120 por omissão, "ADMINISTRADOR" em maiúsculas ficava cortado a meio numa janela
 * estreita.
 */
export const ROLE_COLUMN = { ...closedSet(ROLES), cellRenderer: roleCell, minWidth: 180 };

/** O estado, na pastilha que o resto da dashboard usa. */
function stateCell(params) {
    const enabled = Number(params.value) === 1;

    return stateBadge(enabled ? "Ativo" : "Inativo", enabled ? "success" : "secondary");
}

/** As acções da linha. Os cliques sobem por delegação, como no resto do modal. */
function actionsCell(params) {
    const user = params.data || {};
    const enabled = isEnabled(user);

    return html`
        <div class="d-flex justify-content-end gap-1">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-action="changeApiUserPassword" data-id="${user.id}" title="Mudar password" aria-label="Mudar password"><i class="fa-solid fa-key"></i></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-action="toggleApiUser" data-id="${user.id}" title="${enabled ? "Desativar" : "Ativar"}" aria-label="${enabled ? "Desativar" : "Ativar"}"><i class="fa-solid ${enabled ? "fa-pause" : "fa-play"}"></i></button>
        <button type="button" class="btn btn-outline-danger btn-sm" data-action="deleteApiUser" data-id="${user.id}" title="Apagar" aria-label="Apagar"><i class="fa-solid fa-trash"></i></button>
        </div>`;
}

/** Não vem do descritor: não é um campo do utilizador, são as acções sobre ele. */
const ACTIONS_COLUMN = {
    colId: "actions",
    headerName: "",
    cellRenderer: actionsCell,
    pinned: "right",
    width: 132,
    minWidth: 132,
    resizable: false,
    sortable: false,
    suppressMovable: true,
    lockPosition: "right",
    valueGetter: () => "",
};

export function initSettingsApiUsers(context) {
    els = context.els;
}

const showError = (error) => toast("error", error.message);
const run = (work) => void work.catch(showError);

/** A grelha sabe em que página está: recarregar é pedi-la outra vez. */
const reload = () => (grid === null ? Promise.resolve() : grid.start().catch(showError));

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

export async function loadSettingsApiUsersSection(page = 1) {
    state.settingsModal.sectionLoaded.apiUsers = true;

    try {
        if (grid !== null) {
            await grid.goToPage(page);
            return;
        }

        // O primeiro pedido traz o descritor com que a grelha é construída. As licenças são
        // só do formulário de criar, e vêm da cache partilhada.
        const [first, loaded] = await Promise.all([
            fetchApiUsers({ page: 1, limit: 1 }),
            ensureLicensesLoaded(),
        ]);
        licenses = loaded ?? [];

        grid = createGrid({
            element: els.apiUserGrid,
            columns: first.columns,
            dark: document.documentElement.getAttribute("data-bs-theme") === "dark",
            columnTitles: COLUMN_TITLES,
            valueLabels: VALUE_LABELS,
            emptyMessage: "Nenhum utilizador para este filtro.",
            cellRenderers: {
                role: ROLE_COLUMN,
                enabled: { ...closedSet(["1", "0"]), cellRenderer: stateCell },
            },
            extraColumns: [ACTIONS_COLUMN],
            load: fetchApiUsers,
            save: saveEditedCell,
            onPage: renderApiUsersPage,
            onError: showError,
        });

        els.settingsApiUsersPaginationControls?.addEventListener("click", (event) => {
            const current = state.settingsModal.apiUsersPagination;
            const next = resolvePaginationPage(event, current, "settingsApiUsersPage");
            if (next !== null && next !== current?.page) {
                void grid.goToPage(next);
            }
        });

        await grid.start();
    } catch (error) {
        showError(error);
    }
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

/** Lançar o erro é o que faz a grelha repor o valor antigo da célula. */
export async function saveEditedCell(user, field) {
    await saveUser(user);
    // A empresa e a licença seguem o perfil, e quem lhes mexeu foi o servidor.
    if (field === "role") {
        await reload();
    }
}

async function toggleApiUser(user) {
    await saveUser(user, { enabled: !isEnabled(user) });
    await reload();
}

async function changeApiUserPassword(user) {
    const { isConfirmed, value } = await promptPassword("Nova password", user.username);
    if (!isConfirmed) {
        return;
    }
    await saveUser(user, { password: value });
    toast("success", "Password alterada");
}

export async function deleteApiUser(id) {
    const { isConfirmed } = await confirmDestructive("Apagar utilizador API?");
    if (!isConfirmed) {
        return;
    }
    const result = await apiDeleteApiUser(id);
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
                    <label class="section-label d-block mb-1" for="apiUserNewUsername">Utilizador</label>
                    <input type="text" class="form-control form-control-sm" id="apiUserNewUsername" data-field="username" autocomplete="off">
                </div>
                <div class="col-12 col-md-3">
                    <label class="section-label d-block mb-1" for="apiUserNewPassword">Password</label>
                    <input type="password" class="form-control form-control-sm" id="apiUserNewPassword" data-field="password" autocomplete="new-password">
                </div>
                <div class="col-12 col-md-2">
                    <label class="section-label d-block mb-1" for="apiUserNewRole">Perfil</label>
                    <select class="form-select form-select-sm" id="apiUserNewRole" data-field="role">${raw(roleOptions())}</select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="section-label d-block mb-1" for="apiUserNewLicense">Licença</label>
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
        markInvalid(field("password"), "Password é obrigatória para novo utilizador");
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

/** Os cliques da secção: o formulário de criar, e as acções que a grelha desenha nas linhas. */
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
        deleteApiUser: () => user && run(deleteApiUser(user.id)),
    };
    actions[button.dataset.action]?.();
}

/** O perfil de admin manda em todas as licenças, por isso a escolha de uma não se aplica. */
export function handleApiUserListChange(event) {
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
