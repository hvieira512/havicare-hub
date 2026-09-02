import {
    createCompany as apiCreateCompany,
    deleteCompany as apiDeleteCompany,
    deleteLicense as apiDeleteLicense,
    getCompanies as apiGetCompanies,
    saveLicense as apiSaveLicense,
    updateCompany as apiUpdateCompany,
} from "../api/index.js";
import { ensureLicensesLoaded, invalidateLicenses } from "../licenses.js";
import { stateBadge } from "../components/state-badge.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { apiError, confirmDestructive, toast } from "../dialogs.js";
import { clearInvalid, markInvalid } from "../validation.js";
import { setSettingsNavCount } from "./shell.js";
import { renderPagination } from "../pagination.js";
import { editorOf, focusEditor, inlineEditor } from "./row-editor.js";

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

// Uma vaga só para os dois tipos de linha: abrir uma empresa fecha a licença que estivesse
// aberta, e ao contrário, sem ninguém se lembrar de o fazer.
const editor = inlineEditor(() => renderCompanySection());

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
    editor.reset();
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
    editor.reset();
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
        <div class="tree-row" data-editor="license" data-id="${license?.id || ""}" data-company-id="${companyId}">
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
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelEdit">Cancelar</button>
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
                ${raw(stateBadge(`${owned.length} ${owned.length === 1 ? "licença" : "licenças"}`, "secondary"))}
                <button class="btn btn-outline-secondary btn-sm" data-action="newLicenseForCompany" data-company-id="${company.id}" title="Nova licença" aria-label="Nova licença nesta empresa"><i class="fa-solid fa-plus"></i></button>
                <button class="btn btn-outline-secondary btn-sm" data-action="editCompany" data-id="${company.id}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger btn-sm" data-id="${company.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`;
}

function companyHeaderEditor(company) {
    return html`
        <div class="d-flex align-items-end gap-2 flex-wrap" data-editor="company" data-id="${company?.id || ""}">
            <div class="flex-grow-1" style="min-width:12rem">
                <label class="section-label d-block mb-1" for="companyRowName">Nome da empresa</label>
                <input type="text" class="form-control form-control-sm" id="companyRowName" data-field="name" value="${company?.name || ""}" placeholder="hitcare">
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelEdit">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" data-action="saveCompanyRow">Guardar</button>
            </div>
        </div>`;
}

function companyCard(company) {
    const owned = currentLicenses.filter(
        (license) => String(license.company_id) === String(company.id),
    );
    const rows = owned
        .map((license) => (editor.at("license", license.id)
            ? licenseEditorRow(license, company.id)
            : licenseViewRow(license)))
        .join("");
    // O rascunho de uma licença nova nasce no fim das da empresa em que se carregou no `+`.
    const draft = editor.at("license") && editor.open.companyId === String(company.id)
        ? licenseEditorRow(null, company.id)
        : "";

    return html`
        <div class="card mb-2">
        <div class="card-body p-3">
        ${raw(editor.at("company", company.id)
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
        (editor.at("company")
            ? html`<div class="card mb-2"><div class="card-body p-3">${raw(companyHeaderEditor(null))}</div></div>`
            : "") +
            companies.map(companyCard).join("");

    focusEditor(els.companyListBody);
}

/* ---------- abrir e fechar ---------- */

export function newCompany() {
    editor.draft("company");
}

/* ---------- gravar e apagar ---------- */

async function saveCompanyRow(button) {
    const row = editorOf(button, "company");
    if (!row) return;
    const name = row.value("name");

    clearInvalid(row.el);
    if (!name) {
        markInvalid(row.field("name"), "O nome é obrigatório");
        return;
    }
    const result = await (row.id ? apiUpdateCompany(row.id, name) : apiCreateCompany(name));
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
    const row = editorOf(button, "license");
    if (!row) return;
    const licenseId = row.value("licenseId");

    clearInvalid(row.el);
    if (!licenseId) {
        markInvalid(row.field("licenseId"), "O ID da licença é obrigatório");
        return;
    }
    const result = await apiSaveLicense(row.id, {
        companyId: Number(row.el.dataset.companyId),
        licenseId,
        name: row.value("name"),
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
        editCompany: () => editor.edit("company", button.dataset.id),
        editLicense: () => editor.edit("license", button.dataset.id),
        newLicenseForCompany: () => editor.draft("license", { companyId: button.dataset.companyId }),
        cancelEdit: () => editor.cancel(),
        saveCompanyRow: () => void saveCompanyRow(button),
        saveLicenseRow: () => void saveLicenseRow(button),
        deleteCompany: () => void deleteCompany(Number(button.dataset.id)),
        deleteLicense: () => void deleteLicense(Number(button.dataset.id)),
    };
    actions[button.dataset.action]?.();
}
