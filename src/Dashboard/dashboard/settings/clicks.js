import {state} from "../state.js";
import {deleteApiUser, editApiUser, toggleApiUser} from "./api-users.js";
import {loadSettingsCapabilitiesSection} from "./capabilities.js";
import {selectModelDeviceType, selectModelSupplier} from "./models/form.js";
import {
    capabilityRowsDependOnSelection,
    openModelDetail,
    renderCapabilitiesSection,
    syncCapabilitySwitches,
} from "./models/detail.js";

/**
 * Os cliques dentro do modal de definições: escolher fornecedor ou tipo, ligar e desligar
 * capacidades, navegar o catálogo, e as acções das três listagens. São todos delegados na
 * raiz de cada secção, e todos encontram o botão pelo `data-action`.
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
    if (capabilityRowsDependOnSelection()) {
        renderCapabilitiesSection();
        return;
    }
    syncCapabilitySwitches(feature);
}

/**
 * A tira do catálogo desloca a lista até à secção e não filtra: o catálogo fica todo numa
 * superfície, que é o que serve para auditar um fornecedor de ponta a ponta.
 */
export function scrollCapabilityCatalogSection(event) {
    const chip = event.target.closest(
        '[data-action="scrollCapabilityCatalogSection"]',
    );
    if (!chip) return;

    const target = document.getElementById(chip.dataset.target || "");
    if (!target) return;

    state.settingsModal.activeCapabilityCatalogSection = chip.dataset.target || "";
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

/**
 * Um clique numa folha do catálogo abre a ficha do modelo. A linha é um `div` com
 * `role="button"` e não um `<button>`, porque leva dentro a imagem, dois nomes e a seta, que
 * herdariam o reset de tipografia do Bootstrap -- em troca, o teclado é tratado à mão.
 */
export function handleModelListClick(event) {
    const row = event.target.closest('[data-action="modelCapabilities"]');
    if (!row) return;
    if (event.type === "keydown") {
        if (event.key !== "Enter" && event.key !== " ") return;
        // O espaço numa linha accionável rola a página se ninguém o travar.
        event.preventDefault();
    }
    void openModelDetail(parseInt(row.dataset.id));
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
