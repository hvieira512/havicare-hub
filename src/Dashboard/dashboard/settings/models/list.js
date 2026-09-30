import {
    getDeviceTypeSuppliersModels as apiGetCatalog,
    getModelFilters as apiGetModelFilters,
} from "../../api/index.js";
import { state } from "../../state.js";
import { esc } from "../../format.js";
import { deviceTypeIcon } from "../../components/device-type-tiles.js";
import { modelImageHtml } from "../../components/model-image.js";
import {
    deviceTypeLabel,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
    normalizeDeviceType,
} from "../../domain.js";
import { setSettingsNavCount } from "../shell.js";
import { resetModelWizard } from "./form.js";
import { getSettingsModelsRuntime, modelsCarousel } from "./shell.js";

/**
 * O catálogo: um nível por tipo de dispositivo, e o modelo como folha. O fornecedor é um
 * dado da linha e não uma pasta -- como pasta custava um nível de indentação a dizer o que
 * cabe em duas palavras ao lado do nome.
 */

function plural(count, singular, pluralWord) {
    return `${count} ${count === 1 ? singular : pluralWord}`;
}

/**
 * Os grupos que valem a pena desenhar: a API devolve um por cada tipo do catálogo, e aqui um
 * tipo sem fornecedores é uma moldura vazia e cai fora.
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

/**
 * O que a linha diz a seguir ao nome comercial. O nome interno é o código do fabricante e
 * repete-se muitas vezes com o comercial (D41/D41): quando é outro, diz-se que é interno,
 * porque de outro modo nada distingue os dois nomes.
 */
function modelRowMeta(model, showType) {
    const internal = modelInternalName(model);
    return [
        String(model.supplier || ""),
        showType ? deviceTypeLabel(modelDeviceType(model)) : "",
        internal && internal !== modelCommercialName(model) ? `interno ${internal}` : "",
    ].filter(Boolean);
}

function modelRow(model, { showType = false } = {}) {
    const meta = modelRowMeta(model, showType);

    const name = modelCommercialName(model);

    // Numa linha a partir do `sm`, e em duas abaixo dela: numa calha de telemóvel o nome
    // comercial come a linha toda e o fornecedor -- que é o que a linha ganhou -- desaparecia.
    return `
        <div class="tree-row catalog-model position-relative d-flex align-items-center" data-action="modelCapabilities" data-id="${esc(model.id)}" role="button" tabindex="0">
        <span class="catalog-model-image flex-shrink-0 d-flex align-items-center justify-content-center">${modelImageHtml(model, 28)}</span>
        <span class="flex-grow-1 min-w-0 text-truncate" title="${esc([name, ...meta].join(" · "))}">
        <span class="fw-semibold d-block d-sm-inline text-truncate">${esc(name)}</span>
        <span class="catalog-model-meta small text-secondary d-block d-sm-inline text-truncate"><span class="d-none d-sm-inline"> · </span>${meta.map((part) => esc(part)).join(" · ")}</span>
        </span>
        <i class="fa-solid fa-chevron-right text-secondary flex-shrink-0" aria-hidden="true"></i>
        </div>`;
}

function typeCard(group) {
    const id = `catalogType-${group.deviceType}`;
    const models = group.suppliers.flatMap((supplier) => supplier.models || []);

    return `
        <div class="card mb-2">
        <div class="card-body p-3">
        <button type="button" class="btn btn-link p-0 text-decoration-none text-body d-flex align-items-center gap-2 w-100 text-start"
            data-bs-toggle="collapse" data-bs-target="#${id}" aria-expanded="true" aria-controls="${id}">
        <i class="fa-solid ${esc(deviceTypeIcon(group.deviceType))} text-secondary" aria-hidden="true"></i>
        <span class="fw-semibold">${esc(deviceTypeLabel(group.deviceType))}</span>
        <span class="small text-secondary ms-auto text-end">${plural(models.length, "modelo", "modelos")} · ${plural(group.suppliers.length, "fornecedor", "fornecedores")}</span>
        <i class="fa-solid fa-chevron-down catalog-caret text-secondary" aria-hidden="true"></i>
        </button>
        <div class="collapse show" id="${id}">
        ${models.map((model) => modelRow(model)).join("")}
        </div>
        </div>
        </div>`;
}

/**
 * A busca achata a árvore, senão um resultado fica escondido dentro de um grupo fechado.
 * Sem o cabeçalho do tipo por cima, cada linha passa a dizer também de que tipo é.
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
    const { els } = getSettingsModelsRuntime();
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
                ${results.map((model) => modelRow(model, { showType: true })).join("")}
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
 * O catálogo vem inteiro numa chamada.
 *
 * ponytail: sem paginação. Com centenas de modelos, o caminho é nascerem fechados e buscar
 * os filhos ao abrir, e não cortar um grupo entre duas páginas.
 */
async function loadSettingsModelsSection() {
    const response = await apiGetCatalog();
    state.settingsModal.modelCatalog = response.data || [];
    state.settingsModal.sectionLoaded.models = true;
    // Aqui e não no `renderModelsSection`: a busca redesenha a cada tecla, e o assistente
    // do outro slide não tem nada a ver com isso.
    resetModelWizard();
    renderModelsSection();

    const { els } = getSettingsModelsRuntime();
    // Fechar o modal a meio do assistente deixava o rodapé sem o «Fechar» ao reabrir.
    els.settingsCloseBtn?.classList.remove("d-none");
    if (els.modelsListSearch) {
        els.modelsListSearch.value = state.settingsModal.modelsSearchQuery || "";
    }
}

function handleModelsListSearchInput() {
    const { els } = getSettingsModelsRuntime();
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
    const { els } = getSettingsModelsRuntime();
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
    els.settingsCloseBtn?.classList.remove("d-none");
    els.modelDetailActionBar?.classList.replace("d-flex", "d-none");

    carousel.to(0);

    state.settingsModal.currentCapabilitiesModel = null;
    state.settingsModal.capabilityModelTemplateKeys = [];
    if (state.settingsModal.sectionLoaded.models) {
        renderModelsSection();
        return;
    }
    void loadSettingsModelsSection();
}

/**
 * O rasto e o slide do assistente de um modelo novo. O «Fechar» do rodapé do modal sai:
 * enquanto há formulário por gravar, o rodapé é o do assistente.
 */
function showNewModelSlide() {
    const { els } = getSettingsModelsRuntime();
    els.settingsCloseBtn?.classList.add("d-none");
    els.modelDetailActionBar?.classList.replace("d-flex", "d-none");
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
