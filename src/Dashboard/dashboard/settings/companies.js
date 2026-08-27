import {
    createCompany as apiCreateCompany,
    deleteCompany as apiDeleteCompany,
    deleteLicense as apiDeleteLicense,
    getCompanies as apiGetCompanies,
    getLicenses as apiGetLicenses,
    saveLicense as apiSaveLicense,
    updateCompany as apiUpdateCompany,
} from "../api/index.js";
import {state} from "../state.js";
import {esc} from "../format.js";
import {
    renderSettingsPagination,
    setSettingsNavCount,
    toggleCollapse,
} from "./shell.js";

/**
 * O separador das empresas, com as licenças de cada uma dentro dela. São dois formulários e
 * uma lista só, porque a relação -- uma licença pertence a uma empresa -- é o que o ecrã tem
 * de mostrar, e é por isso que os dois vivem no mesmo módulo.
 */
let els;

export function initSettingsCompanies(context) {
    els = context.els;
}

export async function loadSettingsCompanySection(companiesPage = 1) {
    // As licenças vêm todas de uma vez porque são desenhadas dentro da empresa a que
    // pertencem: paginá-las à parte deixava uma licença fora da página da sua empresa.
    // ponytail: limite de 1000, que é muito acima do real; se um dia passar disso, a
    // resposta é buscar as licenças por empresa e não aumentar o número.
    const [companyData, licensesData] = await Promise.all([
        apiGetCompanies({ page: companiesPage }),
        apiGetLicenses({ page: 1, limit: 1000 }),
    ]);
    const companies = companyData.data || [];
    const licenses = licensesData.data || [];
    state.settingsModal.sectionLoaded.company = true;
    state.settingsModal.companyPagination = companyData.pagination || null;
    renderCompanySection(companies, licenses);
    renderLicensesSection(licenses, companies);
    renderSettingsPagination(
        state.settingsModal.companyPagination,
        els.settingsCompanyPagination,
        els.settingsCompanyPaginationSummary,
        els.settingsCompanyPaginationControls,
        "settingsCompanyPage",
    );
}

function renderCompanySection(companies, licenses) {
    resetCompanyForm();
    if (els.companiesTabSummary) {
        const total = (companies || []).length;
        const owned = (licenses || []).length;
        els.companiesTabSummary.textContent =
            `${total} ${total === 1 ? "empresa" : "empresas"} · ${owned} ${owned === 1 ? "licença" : "licenças"}`;
        setSettingsNavCount("Company", owned);
    }
    // Uma linha por empresa, e as suas licenças indentadas por baixo.
    els.companyListBody.innerHTML = (companies || [])
        .map((item) => {
            const owned = (licenses || []).filter(
                (license) => String(license.company_id) === String(item.id),
            );
            return `
        <div class="card mb-2">
        <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="fw-semibold">${esc(item.name)}</div>
            <div class="d-flex align-items-center gap-2">
                <span class="config-state config-state-secondary"><span class="config-state-dot"></span>${owned.length} ${owned.length === 1 ? "licença" : "licenças"}</span>
                <button class="btn btn-link btn-sm p-0 text-decoration-none" data-action="newLicenseForCompany" data-company-id="${item.id}">Nova licença</button>
                <button class="btn btn-outline-secondary btn-sm" data-action="editCompany" data-id="${item.id}" data-name="${esc(item.name)}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger btn-sm" data-id="${item.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        ${owned.map((license) => `
        <div class="tree-row justify-content-between">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <span class="section-label tabular-nums" style="letter-spacing:0">ID ${esc(license.license_id)}</span>
                <span class="text-truncate">${esc(license.name || "sem nome")}</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <button class="btn btn-outline-secondary btn-sm" data-action="editLicense" data-id="${license.id}" data-company-id="${license.company_id}" data-company-name="${esc(license.company_name || "")}" data-license-id="${esc(license.license_id)}" data-name="${esc(license.name || "")}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger btn-sm" data-id="${license.id}" data-action="deleteLicense" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`).join("")}
        </div>
        </div>`;
        })
        .join("");
}

export function resetCompanyForm() {
    els.companyForm.reset();
    els.companyId.value = "";
    toggleCollapse(els.companyFormCollapse, false);
}

function editCompany(button) {
    els.companyId.value = button.dataset.id || "";
    els.companyName.value = button.dataset.name || "";
    toggleCollapse(els.companyFormCollapse, true);
}

export async function saveCompany() {
    const id = els.companyId.value.trim();
    const name = els.companyName.value.trim();
    if (!name) {
        alert("O nome é obrigatório");
        return;
    }
    const result = await (id
        ? apiUpdateCompany(id, name)
        : apiCreateCompany(name));
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function deleteCompany(id) {
    if (
        !confirm("Apagar empresa? Todas as licenças associadas serão apagadas.")
    )
        return;
    const result = await apiDeleteCompany(id);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

/**
 * As licenças já se desenham dentro da empresa a que pertencem, na lista das empresas: o
 * que fica por fazer aqui é encher o select de empresa do formulário.
 */
function renderLicensesSection(licenses, companies) {
    resetLicenseForm();
    const companyOptions = (companies || [])
        .map((s) => `<option value="${s.id}">${esc(s.name)}</option>`)
        .join("");
    els.licenseCompanySelect.innerHTML =
        '<option value="">Selecionar empresa</option>' + companyOptions;
}

export function resetLicenseForm() {
    els.licenseForm.reset();
    els.licenseId.value = "";
    toggleCollapse(els.licenseFormCollapse, false);
}

function editLicense(button) {
    els.licenseId.value = button.dataset.id || "";
    els.licenseCompanySelect.value = button.dataset.companyId || "";
    els.licenseLicenseId.value = button.dataset.licenseId || "";
    els.licenseName.value = button.dataset.name || "";
    toggleCollapse(els.licenseFormCollapse, true);
}

export async function saveLicense() {
    const id = els.licenseId.value.trim();
    const companyId = els.licenseCompanySelect.value;
    const licenseId = els.licenseLicenseId.value.trim();
    const name = els.licenseName.value.trim();
    if (!companyId) {
        alert("Selecione uma empresa");
        return;
    }
    if (!licenseId) {
        alert("O ID da licença é obrigatório");
        return;
    }
    const body = { companyId: Number(companyId), licenseId, name };
    const result = await apiSaveLicense(id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function deleteLicense(id) {
    if (!confirm("Apagar licença?")) return;
    const result = await apiDeleteLicense(id);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

/** Os cliques da lista: as linhas das licenças estão dentro da empresa a que pertencem. */
export function handleCompanyListClick(event) {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.dataset.action === "editCompany") {
        editCompany(button);
    } else if (button.dataset.action === "deleteCompany") {
        void deleteCompany(Number(button.dataset.id));
    } else if (button.dataset.action === "editLicense") {
        editLicense(button);
    } else if (button.dataset.action === "deleteLicense") {
        void deleteLicense(Number(button.dataset.id));
    } else if (button.dataset.action === "newLicenseForCompany") {
        // A licença nasce dentro da empresa: o formulário abre com a empresa já escolhida.
        resetLicenseForm();
        els.licenseCompanySelect.value = button.dataset.companyId || "";
        toggleCollapse(els.licenseFormCollapse, true);
    }
}
