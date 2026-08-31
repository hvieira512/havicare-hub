import {
    createCompany as apiCreateCompany,
    deleteCompany as apiDeleteCompany,
    deleteLicense as apiDeleteLicense,
    getCompanies as apiGetCompanies,
    saveLicense as apiSaveLicense,
    updateCompany as apiUpdateCompany,
} from "../api/index.js";
import { ensureLicensesLoaded, invalidateLicenses } from "../licenses.js";
import { stateBadge } from "../widgets.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { apiError, confirmDestructive, toast } from "../dialogs.js";
import { clearInvalid, markInvalid } from "../validation.js";
import { setSettingsNavCount } from "./shell.js";
import { renderPagination } from "../pagination.js";

/**
 * O separador das licenças, com as licenças de cada empresa dentro dela.
 *
 * A edição acontece na própria linha. Antes eram dois formulários escondidos: o das empresas
 * no topo do painel e o das licenças no fundo, a seguir à paginação -- carregar no lápis de
 * uma licença abria uma tira sem título lá em baixo, longe da linha de onde se veio e igual
 * à de criar. Agora a linha que se toca é a que se transforma.
 *
 * A empresa deixou de ser uma pergunta do formulário da licença: uma licença nasce dentro da
 * empresa em que se carregou no `+`, e a posição na árvore é que a diz.
 */
let els;
// A página de empresas que está à vista, para uma alteração numa licença a poder redesenhar
// sem ir buscar as empresas outra vez.
let currentCompanies = [];
let currentLicenses = [];

/** `null`, `"new"`, ou o id da empresa aberta para edição. */
let editingCompany = null;
/** `null`, `{ id }` para uma licença existente, ou `{ companyId }` para uma nova. */
let editingLicense = null;

export function initSettingsCompanies(context) {
    els = context.els;
}

export async function loadSettingsCompanySection(companiesPage = 1) {
    // As licenças vêm todas de uma vez porque são desenhadas dentro da empresa a que
    // pertencem: paginá-las à parte deixava uma licença fora da página da sua empresa.
    const [companyData, licenses] = await Promise.all([
        apiGetCompanies({ page: companiesPage }),
        ensureLicensesLoaded(),
    ]);
    currentCompanies = companyData.data || [];
    currentLicenses = licenses ?? [];
    state.settingsModal.sectionLoaded.company = true;
    state.settingsModal.companyPagination = companyData.pagination || null;
    editingCompany = null;
    editingLicense = null;
    renderCompanySection();
    renderPagination({
        pagination: state.settingsModal.companyPagination,
        rootEl: els.settingsCompanyPagination,
        summaryEl: els.settingsCompanyPaginationSummary,
        controlsEl: els.settingsCompanyPaginationControls,
        actionPrefix: "settingsCompanyPage",
    });
}

/** Depois de mexer numa empresa: as licenças em cache continuam a servir. */
function reloadCompanies() {
    return loadSettingsCompanySection(
        Number(state.settingsModal.companyPagination?.page || 1) || 1,
    );
}

/** Depois de mexer numa licença: as empresas não mudaram e não se vão buscar de novo. */
async function reloadLicenses() {
    invalidateLicenses();
    currentLicenses = (await ensureLicensesLoaded()) ?? [];
    editingCompany = null;
    editingLicense = null;
    renderCompanySection();
}

/* ---------- as linhas ---------- */

function licenseViewRow(license) {
    return html`
        <div class="tree-row justify-content-between">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <span class="section-label tabular-nums" style="letter-spacing:0">ID ${license.license_id}</span>
                <span class="text-truncate">${license.name || "sem nome"}</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <button class="btn btn-outline-secondary btn-sm" data-action="editLicense" data-id="${license.id}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger btn-sm" data-id="${license.id}" data-action="deleteLicense" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`;
}

/**
 * Uma licença aberta. Sem a pergunta da empresa: ela está a ser editada dentro da empresa a
 * que pertence, e repetir a pergunta era pedir outra vez o que a posição já diz.
 */
function licenseEditorRow(license, companyId) {
    return html`
        <div class="tree-row" data-license-editor data-id="${license?.id || ""}" data-company-id="${companyId}">
            <div class="d-flex align-items-end gap-2 flex-wrap w-100">
                <div style="width:8rem">
                    <label class="section-label d-block mb-1" for="licenseRowId">ID da licença</label>
                    <input type="text" class="form-control form-control-sm tabular-nums" id="licenseRowId" data-field="licenseId" inputmode="numeric" value="${license?.license_id || ""}" placeholder="1001">
                </div>
                <div class="flex-grow-1" style="min-width:12rem">
                    <label class="section-label d-block mb-1" for="licenseRowName">Nome</label>
                    <input type="text" class="form-control form-control-sm" id="licenseRowName" data-field="name" value="${license?.name || ""}" placeholder="gucc.dev">
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelLicenseEdit">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" data-action="saveLicenseRow">Guardar</button>
                </div>
            </div>
        </div>`;
}

function companyHeaderView(company, owned) {
    return html`
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="fw-semibold">${company.name}</div>
            <div class="d-flex align-items-center gap-2">
                ${raw(stateBadge(`${owned.length} ${owned.length === 1 ? "licença" : "licenças"}`, "config-state-secondary"))}
                <button class="btn btn-outline-secondary btn-sm" data-action="newLicenseForCompany" data-company-id="${company.id}" title="Nova licença" aria-label="Nova licença nesta empresa"><i class="fa-solid fa-plus"></i></button>
                <button class="btn btn-outline-secondary btn-sm" data-action="editCompany" data-id="${company.id}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger btn-sm" data-id="${company.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`;
}

function companyHeaderEditor(company) {
    return html`
        <div class="d-flex align-items-end gap-2 flex-wrap" data-company-editor data-id="${company?.id || ""}">
            <div class="flex-grow-1" style="min-width:12rem">
                <label class="section-label d-block mb-1" for="companyRowName">Nome da empresa</label>
                <input type="text" class="form-control form-control-sm" id="companyRowName" data-field="name" value="${company?.name || ""}" placeholder="hitcare">
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelCompanyEdit">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" data-action="saveCompanyRow">Guardar</button>
            </div>
        </div>`;
}

function companyCard(company) {
    const owned = currentLicenses.filter(
        (license) => String(license.company_id) === String(company.id),
    );
    const rows = owned
        .map((license) => (String(editingLicense?.id) === String(license.id)
            ? licenseEditorRow(license, company.id)
            : licenseViewRow(license)))
        .join("");
    // O rascunho de uma licença nova nasce no fim das da empresa em que se carregou no `+`.
    const draft = !editingLicense?.id && String(editingLicense?.companyId) === String(company.id)
        ? licenseEditorRow(null, company.id)
        : "";

    return html`
        <div class="card mb-2">
        <div class="card-body p-3">
        ${raw(String(editingCompany) === String(company.id)
            ? companyHeaderEditor(company)
            : companyHeaderView(company, owned))}
        ${raw(rows)}${raw(draft)}
        </div>
        </div>`;
}

function renderCompanySection() {
    const companies = currentCompanies || [];
    if (els.companiesTabSummary) {
        const total = companies.length;
        const owned = currentLicenses.length;
        els.companiesTabSummary.textContent =
            `${total} ${total === 1 ? "empresa" : "empresas"} · ${owned} ${owned === 1 ? "licença" : "licenças"}`;
        setSettingsNavCount("Company", owned);
    }

    els.companyListBody.innerHTML =
        (editingCompany === "new"
            ? html`<div class="card mb-2"><div class="card-body p-3">${raw(companyHeaderEditor(null))}</div></div>`
            : "") +
            companies.map(companyCard).join("");

    els.companyListBody.querySelector("[data-field]")?.focus();
}

/* ---------- abrir e fechar ---------- */

export function newCompany() {
    editingCompany = "new";
    editingLicense = null;
    renderCompanySection();
}

function editCompany(button) {
    editingCompany = button.dataset.id || null;
    editingLicense = null;
    renderCompanySection();
}

export function cancelCompanyEdit() {
    editingCompany = null;
    renderCompanySection();
}

function editLicense(button) {
    editingLicense = { id: button.dataset.id };
    editingCompany = null;
    renderCompanySection();
}

function newLicenseForCompany(button) {
    editingLicense = { companyId: button.dataset.companyId };
    editingCompany = null;
    renderCompanySection();
}

export function cancelLicenseEdit() {
    editingLicense = null;
    renderCompanySection();
}

/* ---------- gravar e apagar ---------- */

async function saveCompanyRow(button) {
    const editor = button.closest("[data-company-editor]");
    if (!editor) return;
    const id = editor.dataset.id || "";
    const field = editor.querySelector("[data-field=\"name\"]");
    const name = field.value.trim();

    clearInvalid(editor);
    if (!name) {
        markInvalid(field, "O nome é obrigatório");
        return;
    }
    const result = await (id ? apiUpdateCompany(id, name) : apiCreateCompany(name));
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    await reloadCompanies();
}

async function deleteCompany(id) {
    const { isConfirmed } = await confirmDestructive(
        "Apagar empresa?",
        "Todas as licenças associadas serão apagadas.",
    );
    if (!isConfirmed) return;
    const result = await apiDeleteCompany(id);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    // Apagar a empresa apaga as licenças dela: a cache das licenças deixa de valer.
    invalidateLicenses();
    await reloadCompanies();
}

async function saveLicenseRow(button) {
    const editor = button.closest("[data-license-editor]");
    if (!editor) return;
    const id = editor.dataset.id || "";
    const licenseField = editor.querySelector("[data-field=\"licenseId\"]");
    const licenseId = licenseField.value.trim();
    const name = editor.querySelector("[data-field=\"name\"]").value.trim();

    clearInvalid(editor);
    if (!licenseId) {
        markInvalid(licenseField, "O ID da licença é obrigatório");
        return;
    }
    const result = await apiSaveLicense(id, {
        companyId: Number(editor.dataset.companyId),
        licenseId,
        name,
    });
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    await reloadLicenses();
}

async function deleteLicense(id) {
    const { isConfirmed } = await confirmDestructive("Apagar licença?");
    if (!isConfirmed) return;
    const result = await apiDeleteLicense(id);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    await reloadLicenses();
}

/** Os cliques da lista: as linhas das licenças estão dentro da empresa a que pertencem. */
export function handleCompanyListClick(event) {
    const button = event.target.closest("button");
    if (!button) return;
    const actions = {
        editCompany: () => editCompany(button),
        cancelCompanyEdit: () => cancelCompanyEdit(),
        saveCompanyRow: () => void saveCompanyRow(button),
        deleteCompany: () => void deleteCompany(Number(button.dataset.id)),
        editLicense: () => editLicense(button),
        cancelLicenseEdit: () => cancelLicenseEdit(),
        saveLicenseRow: () => void saveLicenseRow(button),
        deleteLicense: () => void deleteLicense(Number(button.dataset.id)),
        newLicenseForCompany: () => newLicenseForCompany(button),
    };
    actions[button.dataset.action]?.();
}
