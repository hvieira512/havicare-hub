import {
    getDeviceTypeSuppliersModels as apiGetCatalog,
    getModelFilters as apiGetModelFilters,
} from "../../api/index.js";
import {state} from "../../state.js";
import {esc} from "../../format.js";
import {deviceTypeIcon, modelImageHtml, stateBadge} from "../../widgets.js";
import {
    deviceTypeLabel,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
    normalizeDeviceType,
} from "../../domain.js";
import {setSettingsNavCount} from "../shell.js";
import {resetModelForm} from "./form.js";
import {getSettingsModelsRuntime, modelsCarousel} from "./shell.js";

/**
 * O catálogo: tipo de dispositivo, fornecedor, modelo. Três níveis porque a forma dos dados
 * tem três -- um fornecedor suporta *tipos* de dispositivo, e é por isso que existe a tabela
 * `supplier_device_types`. A MOKO aparece em gateways e em pulseiras, e isso não é
 * duplicação: são duas coisas diferentes de suportar.
 */

/** Um id de `collapse` que sobrevive a nomes com espaços e acentos. */
function slug(value) {
    return String(value)
        .toLowerCase()
        .normalize("NFD")
        .replace(/\p{Diacritic}/gu, "")
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/(^-|-$)/g, "");
}

function plural(count, singular, pluralWord) {
    return `${count} ${count === 1 ? singular : pluralWord}`;
}

/**
 * Os grupos que valem a pena desenhar. A API devolve um grupo por cada tipo que existe no
 * catálogo de capacidades, tenha ou não modelos, para quem monta selectores os ter todos;
 * aqui um tipo sem fornecedores é uma moldura vazia, e cai fora.
 *
 * Um par fornecedor×tipo registado mas ainda sem modelos também não aparece, porque a
 * resposta é construída a partir dos modelos. O formulário continua a oferecer o par a
 * partir dos filtros, que é o que importa para criar o primeiro.
 */
function catalogGroups() {
    return (state.settingsModal.modelCatalog || [])
        .map((group) => ({
            deviceType: normalizeDeviceType(
                group?.deviceType || group?.device_type || "watch",
            ),
            suppliers: (Array.isArray(group?.suppliers) ? group.suppliers : [])
                .filter((supplier) => (supplier?.models || []).length > 0),
        }))
        .filter((group) => group.suppliers.length > 0);
}

function modelRow(model, {showOrigin = false} = {}) {
    const commercial = modelCommercialName(model);
    const internal = modelInternalName(model);
    // O nome interno é o código do fabricante e repete-se muitas vezes com o comercial
    // (D41/D41): quando são iguais não vale a pena escrever duas vezes.
    const subtitle = showOrigin
        ? `${esc(model.supplier || "")} · ${esc(deviceTypeLabel(modelDeviceType(model)))}`
        : (internal && internal !== commercial ? esc(internal) : "");

    return `
        <div class="tree-row tree-row-nested catalog-model" data-action="modelCapabilities" data-id="${esc(model.id)}" role="button" tabindex="0">
        <span class="catalog-model-image flex-shrink-0">${modelImageHtml(model, 28)}</span>
        <span class="flex-grow-1 min-w-0">
        <span class="d-block text-truncate fw-semibold">${esc(commercial)}</span>
        ${subtitle ? `<span class="section-label d-block text-truncate">${subtitle}</span>` : ""}
        </span>
        <i class="fa-solid fa-chevron-right text-secondary flex-shrink-0" aria-hidden="true"></i>
        </div>`;
}

function supplierNode(group, supplier) {
    const models = supplier.models || [];
    const id = `catalogSupplier-${slug(group.deviceType)}-${slug(supplier.name)}`;

    return `
        <div class="tree-row">
        <button type="button" class="btn btn-link p-0 text-decoration-none text-body d-flex align-items-center gap-2 flex-grow-1 min-w-0 text-start"
            data-bs-toggle="collapse" data-bs-target="#${id}" aria-expanded="true" aria-controls="${id}">
        <i class="fa-solid fa-chevron-down catalog-caret" aria-hidden="true"></i>
        <span class="fw-semibold text-truncate">${esc(supplier.name)}</span>
        <span class="count-chip count-chip-strong">${models.length}</span>
        </button>
        </div>
        <div class="collapse show" id="${id}">
        ${models.map((model) => modelRow(model)).join("")}
        </div>`;
}

function typeCard(group) {
    const id = `catalogType-${slug(group.deviceType)}`;
    const models = group.suppliers.reduce(
        (total, supplier) => total + (supplier.models || []).length,
        0,
    );

    return `
        <div class="card mb-2">
        <div class="card-body p-3">
        <button type="button" class="btn btn-link p-0 text-decoration-none text-body d-flex align-items-center gap-2 w-100 text-start"
            data-bs-toggle="collapse" data-bs-target="#${id}" aria-expanded="true" aria-controls="${id}">
        <i class="fa-solid ${esc(deviceTypeIcon(group.deviceType))} text-secondary" aria-hidden="true"></i>
        <span class="fw-semibold">${esc(deviceTypeLabel(group.deviceType))}</span>
        ${stateBadge(
            `${plural(models, "modelo", "modelos")} · ${plural(group.suppliers.length, "fornecedor", "fornecedores")}`,
            "config-state-secondary",
            "ms-auto",
        )}
        <i class="fa-solid fa-chevron-down catalog-caret text-secondary" aria-hidden="true"></i>
        </button>
        <div class="collapse show" id="${id}">
        ${group.suppliers.map((supplier) => supplierNode(group, supplier)).join("")}
        </div>
        </div>
        </div>`;
}

/**
 * A busca achata a árvore: agrupar durante uma busca esconde resultados dentro de grupos, e
 * um resultado que não se vê não é um resultado. Achatada, cada linha volta a dizer de quem
 * é e de que tipo, que é o que a árvore dizia pela posição.
 *
 * Filtra em memória porque o catálogo inteiro está em memória. O `/api/models?model=`
 * continua a existir para quem consome a API.
 */
function searchResults(query) {
    const needle = query.toLowerCase();
    return catalogGroups()
        .flatMap((group) =>
            group.suppliers.flatMap((supplier) => supplier.models || []),
        )
        .filter((model) =>
            [
                modelCommercialName(model),
                modelInternalName(model),
                String(model.supplier || ""),
                deviceTypeLabel(modelDeviceType(model)),
            ].some((field) => field.toLowerCase().includes(needle)),
        );
}

function renderModelsSection() {
    const {els} = getSettingsModelsRuntime();
    const query = state.settingsModal.modelsSearchQuery || "";
    const groups = catalogGroups();
    const models = groups.reduce(
        (total, group) =>
            total +
            group.suppliers.reduce(
                (sum, supplier) => sum + (supplier.models || []).length,
                0,
            ),
        0,
    );
    // Um fornecedor que sirva dois tipos conta uma vez: é a resposta a "quantos
    // fornecedores temos", e não a "quantos nós tem a árvore".
    const suppliers = new Set(
        groups.flatMap((group) =>
            group.suppliers.map((supplier) => String(supplier.name || "")),
        ),
    ).size;

    if (query !== "") {
        const results = searchResults(query);
        els.modelCatalog.innerHTML = results.length === 0
            ? `<div class="text-secondary py-4 text-center">Nenhum modelo encontrado para “${esc(query)}”.</div>`
            : `<div class="card"><div class="card-body p-3">
                ${results.map((model) => modelRow(model, {showOrigin: true})).join("")}
                </div></div>`;
        if (els.modelsTabSummary) {
            els.modelsTabSummary.textContent = results.length === 0
                ? "Sem resultados"
                : `${plural(results.length, "resultado", "resultados")} de ${models}`;
        }
    } else {
        els.modelCatalog.innerHTML = groups.map(typeCard).join("");
        if (els.modelsTabSummary) {
            els.modelsTabSummary.textContent =
                `${plural(groups.length, "tipo", "tipos")} · ${plural(suppliers, "fornecedor", "fornecedores")} · ${plural(models, "modelo", "modelos")}`;
        }
    }

    setSettingsNavCount("Models", models);
}

async function loadSettingsModelFilters() {
    if (state.settingsModal.sectionLoaded.modelFilters) {
        return state.settingsModal.modelFilters;
    }

    const response = await apiGetModelFilters();
    const filters = response.data || [];
    state.settingsModal.modelFilters = filters;
    state.settingsModal.sectionLoaded.modelFilters = true;
    return filters;
}

/**
 * O catálogo vem inteiro numa chamada -- tipos, fornecedores e modelos.
 *
 * ponytail: sem paginação, de propósito. São dezasseis modelos; se um dia forem centenas, o
 * caminho é nascerem fechados e buscar os filhos ao abrir (`/api/models?supplier=`), e não
 * cortar um grupo ao meio entre duas páginas.
 */
async function loadSettingsModelsSection() {
    const response = await apiGetCatalog();
    state.settingsModal.modelCatalog = response.data || [];
    state.settingsModal.sectionLoaded.models = true;
    // Aqui e não no `renderModelsSection`: a busca redesenha a cada tecla, e o formulário
    // do outro slide não tem nada a ver com isso.
    resetModelForm();
    renderModelsSection();

    const {els} = getSettingsModelsRuntime();
    if (els.modelsListSearch) {
        els.modelsListSearch.value = state.settingsModal.modelsSearchQuery || "";
    }
}

function handleModelsListSearchInput() {
    const {els} = getSettingsModelsRuntime();
    state.settingsModal.modelsSearchQuery = els.modelsListSearch.value.trim();
    // Sem espera: o filtro é local, e um debounce sobre uma lista em memória era atraso a
    // fingir de rede.
    renderModelsSection();
}

/**
 * Volta ao primeiro slide, que é a lista. Só a vai buscar outra vez quando alguma coisa que
 * a árvore mostra -- nome, fornecedor, tipo, imagem -- mudou; quem a mudou di-lo baixando o
 * `sectionLoaded.models`. Ligar capacidades não mexe em nada disso.
 */
function backToModelList() {
    const {els} = getSettingsModelsRuntime();
    const carousel = state.settingsModal.modelsCarousel;
    if (!carousel) return;

    // Já na lista não há nada a fazer: o rasto e o carrossel já estão onde deviam.
    if (
        carousel._element.querySelector(".carousel-item.active") ===
        carousel._element.firstElementChild?.firstElementChild
    ) {
        return;
    }

    // Na lista o rasto tem um só degrau e repetiria o título logo por baixo.
    els.modelsBreadcrumb.classList.add("d-none");
    els.modelsBreadcrumbModels.classList.add("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbNew.classList.remove("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");
    els.modelsBreadcrumbCurrent.textContent = "";

    carousel.to(0);

    state.settingsModal.currentCapabilitiesModel = null;
    state.settingsModal.capabilityModelTemplateKeys = [];
    if (state.settingsModal.sectionLoaded.models) {
        renderModelsSection();
        return;
    }
    void loadSettingsModelsSection();
}

/** O rasto e o slide do formulário de um modelo novo. */
function showNewModelSlide() {
    const {els} = getSettingsModelsRuntime();
    els.modelsBreadcrumb.classList.remove("d-none");
    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.textContent = "Novo modelo";
    els.modelsBreadcrumbNew.classList.remove("d-none");
    els.modelsBreadcrumbNew.classList.add("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");

    modelsCarousel()?.to(1);
}

export {
    backToModelList,
    handleModelsListSearchInput,
    loadSettingsModelFilters,
    loadSettingsModelsSection,
    renderModelsSection,
    showNewModelSlide,
};
