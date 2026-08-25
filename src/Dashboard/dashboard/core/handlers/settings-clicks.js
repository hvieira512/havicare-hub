import {state} from "../../state.js";
import {
    deleteApiUser,
    editApiUser,
    selectModelDeviceType,
    selectModelSupplier,
    selectModelsDeviceType,
    selectModelsSupplier,
    toggleApiUser,
    toggleSupplier,
} from "../../settings/index.js";
import {
    loadSettingsCapabilitiesSection,
    openModelDetail,
    renderCapabilitiesSection,
} from "../../settings/capabilities.js";

/**
 * Os cliques dentro do modal de definicoes: escolher fornecedor ou tipo, ligar e desligar
 * capacidades, navegar o catalogo, e as accoes das tres listagens.
 *
 * Sao todos delegados na raiz de cada seccao, e todos fazem a mesma coisa: encontram o
 * botao pelo `data-action` e chamam quem sabe do assunto.
 */
let els = {};

export function initSettingsClickHandlers(context) {
    els = context.els;
}

export function handleModelSupplierClick(event) {
    const button = event.target.closest('[data-action="selectModelSupplier"]');
    if (button) selectModelSupplier(button.dataset.value);
}

export function handleModelDeviceTypeClick(event) {
    const button = event.target.closest(
        '[data-action="selectModelDeviceType"]',
    );
    if (button) selectModelDeviceType(button.dataset.value);
}

export function handleModelsDeviceTypeClick(event) {
    const button = event.target.closest(
        '[data-action="selectModelsDeviceType"]',
    );
    if (button) selectModelsDeviceType(button.dataset.value);
}

export function handleModelsSupplierClick(event) {
    const button = event.target.closest('[data-action="selectModelsSupplier"]');
    if (button) selectModelsSupplier(button.dataset.value);
}

export function handleCapabilityDeviceTypeClick(event) {
    const button = event.target.closest(
        '[data-action="selectCapabilityDeviceType"]',
    );
    if (!button) return;
    void loadSettingsCapabilitiesSection(button.dataset.value);
}

export function handleCapabilityGroupsChange(event) {
    const checkbox = event.target.closest([
        '[data-action="toggleCapabilitySupport"]',
        '[data-action="toggleCapabilityRequestability"]',
    ].join(","));
    if (!checkbox) return;

    const feature = String(checkbox.dataset.feature || "");
    if (!feature) return;

    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const requestable = new Set(
        state.settingsModal.capabilityRequestableCapabilities || [],
    );
    if (checkbox.dataset.action === "toggleCapabilitySupport") {
        if (checkbox.checked) {
            enabled.add(feature);
        } else {
            enabled.delete(feature);
            requestable.delete(feature);
        }
    } else {
        if (checkbox.checked && enabled.has(feature)) {
            requestable.add(feature);
        } else {
            requestable.delete(feature);
        }
    }
    state.settingsModal.capabilityEnabledCapabilities = [...enabled];
    state.settingsModal.capabilityRequestableCapabilities = [...requestable];
    renderCapabilitiesSection();
}

/**
 * A tira do catalogo desloca a lista ate a seccao, e nao filtra: o catalogo fica todo numa
 * superficie, que e o que serve para auditar um fornecedor de ponta a ponta.
 */
export function scrollCapabilityCatalogSection(event) {
    const chip = event.target.closest(
        '[data-action="scrollCapabilityCatalogSection"]',
    );
    if (!chip) return;

    const target = document.getElementById(chip.dataset.target || "");
    if (!target) return;

    els.capabilityCatalogSectionNav
        .querySelectorAll(".capability-section-chip")
        .forEach((other) => other.classList.toggle("selected", other === chip));

    target.scrollIntoView({
        block: "start",
        behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
            ? "auto"
            : "smooth",
    });
}

export function jumpCapabilitySection(event) {
    const button = event.target.closest(
        '[data-action="jumpCapabilitySection"]',
    );
    if (!button) return;

    const section = button.dataset.section;
    if (!section) return;

    state.settingsModal.activeCapabilitySection = section;
    renderCapabilitiesSection();
}

export function handleSupplierListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const { id, action, enabled, supplier } = button.dataset;
    if (action === "toggleSupplier") toggleSupplier(parseInt(id), !!enabled);
    if (action === "openSupplierModels") openSupplierModels(supplier || "");
}

/**
 * A contagem de modelos de um fornecedor leva ao separador dos modelos, já filtrado.
 *
 * Marcar a secção como não carregada é o que faz o `shown.bs.tab` do separador ir buscar
 * a lista com o filtro novo -- não se chama o load aqui para não ir duas vezes.
 */
function openSupplierModels(supplier) {
    state.settingsModal.modelsSupplier = supplier;
    state.settingsModal.modelsPage = 1;
    state.settingsModal.sectionLoaded.models = false;
    bootstrap.Tab.getOrCreateInstance(els.settingsModelsTabBtn).show();
}

export function handleModelListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    if (button.dataset.action === "modelCapabilities") {
        void openModelDetail(parseInt(button.dataset.id));
    }
}

export function handleApiUserListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    if (button.dataset.action === "editApiUser") {
        editApiUser(button);
    }
    if (button.dataset.action === "toggleApiUser") {
        toggleApiUser(button);
    }
    if (button.dataset.action === "deleteApiUser") {
        deleteApiUser(parseInt(button.dataset.id));
    }
}
