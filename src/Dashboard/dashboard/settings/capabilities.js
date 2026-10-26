import { loadModelFilters } from "./shell.js";
import { state } from "../core/state.js";
import { html } from "../core/html.js";
import { buttonGroup } from "./button-group.js";
import { sectionStrip } from "../components/chips.js";
import { deviceTypeTiles } from "../components/device-type-tiles.js";
import { cardIcon } from "../components/cards/telemetry.js";
import { CAPABILITY_SECTION_ICONS, ensureCapabilityCatalog, ensureModelTemplate } from "../core/capability-catalog.js";
import {
    capabilitiesGroupedBySection,
    deviceTypeOptions,
    normalizeDeviceType,
} from "../core/domain.js";
import { capabilityLabel, countLabel } from "../core/i18n.js";
import { els } from "../core/dom.js";

/** O catálogo de um tipo de dispositivo, só de leitura; as que o fornecedor não declara ficam esbatidas. */
const blankView = () => ({
    deviceType: "",
    supplier: "",
    query: "",
    templateEnabledKeys: [],
    suppliers: [],
    activeSection: "",
});

let view = blankView();

/** Abrir as definições recomeça o catálogo no tipo de omissão, sem fornecedor nem busca. */
function resetCapabilityCatalogView() {
    view = blankView();
}

/** O que entra no catálogo: as acções -- desligar, reiniciar, encontrar -- são o quarto termo. */
export function showsInCatalog(entry) {
    return Boolean(
        entry.isTelemetry ||
        entry.isConfigurable ||
        entry.isEvent ||
        entry.isRequestable,
    );
}

/** O que se diz à direita do nome; uma que só se pode pedir, sem ser telemetria nem definição, é acção. */
export function capabilityFacts(entry) {
    if (entry.isRequestable && !entry.isTelemetry && !entry.isConfigurable) {
        return ["Ação"];
    }

    return [
        entry.isConfigurable ? "Configurável" : null,
        entry.isRequestable ? "Solicitável" : null,
    ].filter(Boolean);
}

function initSettingsCapabilities() {
    els.capabilityCatalogSectionNav?.addEventListener("click", scrollCapabilityCatalogSection);
    els.capabilityDeviceTypeButtons.addEventListener("click", handleCapabilityDeviceTypeClick);
    els.capabilitySupplierButtons.addEventListener("click", handleCapabilitySupplierClick);
    els.capabilityCatalogSearch?.addEventListener("input", handleCapabilityCatalogSearch);
}

/** O catálogo do tipo escolhido; a cache vive no `capability-catalog.js`. */
async function loadCapabilityCatalog(deviceType) {
    const catalog = await ensureCapabilityCatalog(
        deviceType || view.deviceType || "watch",
    );
    state.settingsModal.capabilityCatalog = catalog;
    return catalog;
}

function loadCapabilitySuppliersForDeviceType(deviceType) {
    const group = state.settingsModal.modelFilters.find(
        (g) => normalizeDeviceType(g.deviceType || "") === deviceType,
    );
    view.suppliers = group?.suppliers || [];
}

async function loadCapabilityTemplate(supplierId, deviceType) {
    if (!supplierId || !deviceType) {
        view.templateEnabledKeys = [];
        return;
    }
    const response = await ensureModelTemplate(supplierId, deviceType);
    if (response.error) {
        view.templateEnabledKeys = [];
        return;
    }
    view.templateEnabledKeys = Array.isArray(
        response.enabledCapabilities,
    )
        ? response.enabledCapabilities.map(String)
        : [];
}

async function loadSettingsCapabilitiesSection(
    deviceType = view.deviceType || "watch",
) {
    const normalized = normalizeDeviceType(deviceType);
    const deviceTypeChanged =
        view.deviceType !== normalized;
    view.deviceType = normalized;
    if (deviceTypeChanged) {
        view.supplier = "";
        view.templateEnabledKeys = [];
    }
    await loadCapabilityCatalog(normalized);
    await loadModelFilters();
    loadCapabilitySuppliersForDeviceType(normalized);
    if (view.supplier) {
        await loadCapabilityTemplate(
            view.supplier,
            normalized,
        );
    }
    state.settingsModal.sectionLoaded.capabilities = true;
    renderCapabilitiesCatalogSection();
    // A caixa segue o estado: o texto sobrevive ao fecho do modal, e o filtro não.
    if (els.capabilityCatalogSearch) {
        els.capabilityCatalogSearch.value = view.query || "";
    }
}

function handleCapabilityDeviceTypeClick(event) {
    const button = event.target.closest(
        "[data-action=\"selectCapabilityDeviceType\"]",
    );
    if (!button) return;
    void loadSettingsCapabilitiesSection(button.dataset.value);
}

function handleCapabilitySupplierClick(event) {
    const button = event.target.closest(
        "[data-action=\"selectCapabilitySupplier\"]",
    );
    if (!button) return;
    selectCapabilitySupplier(button.dataset.value);
}

async function selectCapabilitySupplier(supplierId) {
    view.supplier = supplierId || "";
    const deviceType = view.deviceType || "watch";
    if (supplierId) {
        await loadCapabilityTemplate(supplierId, deviceType);
    } else {
        view.templateEnabledKeys = [];
    }
    renderCapabilitiesCatalogSection();
}

function renderCapabilitiesCatalogSection() {
    els.capabilityDeviceTypeButtons.innerHTML = deviceTypeTiles(deviceTypeOptions, {
        selected: view.deviceType || "watch",
        action: "selectCapabilityDeviceType",
    });

    const supplierId = view.supplier;
    const enabledKeys = view.templateEnabledKeys;
    const enabledSet = new Set(enabledKeys);
    const hasSupplierFilter = !!supplierId && enabledKeys.length > 0;
    const supplier = supplierId
        ? view.suppliers.find(
                (candidate) => String(candidate.id) === String(supplierId),
            )
        : null;

    renderCapabilitySupplierButtons();
    updateCapabilitySupplierSummary();

    const sections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
    );
    const visibleSections = sections
        .map(({ section, label, entries }) => {
            const visibleEntries = entries
                .filter(showsInCatalog)
                .filter((entry) => matchesCapabilityQuery(entry))
                .map((entry) => ({
                    ...entry,
                    supported: !hasSupplierFilter || enabledSet.has(entry.key),
                }));
            if (visibleEntries.length === 0) {
                return null;
            }
            return { section, label, entries: visibleEntries };
        })
        .filter(Boolean);

    els.capabilityCatalogEmpty.classList.toggle(
        "d-none",
        visibleSections.length > 0,
    );
    // Sem a chave nem o tipo: o vocabulário do protocolo não serve quem administra o hub.
    const supplierName = supplier ? supplier.name : "";

    // O catálogo reconstrói-se a cada tecla: mantém-se aberto o que estava aberto.
    const openBodies = new Set(
        Array.from(els.capabilityCatalogViewer.querySelectorAll(".collapse.show"))
            .map((body) => body.id),
    );

    els.capabilityCatalogViewer.innerHTML = visibleSections
        .map(({ section, label, entries }) => {
            const supported = entries.filter((entry) => entry.supported).length;

            const rows = entries
                .map((entry) => {
                    const facts = entry.supported
                        ? capabilityFacts(entry)
                        : [supplierName ? `não oferecido pela ${supplierName}` : "não oferecido"];
                    // As secções irmãs vão na mesma cor, que cores diferentes leem-se como gravidades.
                    return html`
                <div class="capability-row d-grid border rounded-2${entry.supported ? "" : " is-unsupported"}">
                    <span class="d-flex justify-content-center text-secondary"><i class="fa-solid ${capabilityIcon(entry, section)}"></i></span>
                    <span class="fw-semibold min-w-0">${capabilityLabel(entry.key)}</span>
                    <span class="capability-facts text-secondary">${facts.join(" · ")}</span>
                </div>`;
                });

            // Em telefone cada secção fecha; a partir de `sm`, o `d-sm-block` deixa-as todas abertas.
            const bodyId = `${catalogSectionId(section)}Body`;
            const count = supported === entries.length
                ? countLabel(supported, "capacidade", "capacidades")
                : `${supported} de ${entries.length}`;

            return html`
        <section id="${catalogSectionId(section)}" class="capability-catalog-section">
            <button type="button"
                class="d-flex justify-content-between align-items-center gap-2 w-100 mb-2 p-0 py-1 border-0 bg-transparent text-start"
                data-bs-toggle="collapse" data-bs-target="#${bodyId}"
                aria-expanded="false" aria-controls="${bodyId}">
                <span class="section-label">${label}</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="small text-secondary">${count}</span>
                    <i class="fa-solid fa-chevron-down small text-secondary d-sm-none" aria-hidden="true"></i>
                </span>
            </button>
            <div class="collapse d-sm-block" id="${bodyId}">
                <div class="vstack gap-2">${rows}</div>
            </div>
        </section>
    `;
        })
        .join("");

    for (const body of els.capabilityCatalogViewer.querySelectorAll(".collapse")) {
        if (!openBodies.has(body.id)) continue;
        body.classList.add("show");
        els.capabilityCatalogViewer
            .querySelector(`[data-bs-target="#${CSS.escape(body.id)}"]`)
            ?.setAttribute("aria-expanded", "true");
    }

    renderCapabilityCatalogSectionNav(visibleSections);
}

/** O ícone de uma capacidade: o dos cartões de pedido, ou o da secção quando não o há. */
function capabilityIcon(entry, section) {
    const mapped = cardIcon(entry.key);
    if (mapped !== "fa-circle-info") return mapped;
    return CAPABILITY_SECTION_ICONS[section] || "fa-circle-info";
}

function catalogSectionId(section) {
    return `capabilityCatalog-${String(section).replace(/[^a-zA-Z0-9_-]/g, "-")}`;
}

/** A tira de secções, cada pastilha com a contagem do que o fornecedor suporta. */
function renderCapabilityCatalogSectionNav(sections) {
    if (!els.capabilityCatalogSectionNav) return;

    // Com uma secção só, uma tira para saltar para ela não leva a lado nenhum.
    els.capabilityCatalogSectionNav.innerHTML = sections.length > 1
        ? sectionStrip(
                sections.map(({ section, label, entries }) => ({
                    key: catalogSectionId(section),
                    label,
                    count: entries.filter((entry) => entry.supported).length,
                    icon: CAPABILITY_SECTION_ICONS[section] || "fa-circle-info",
                })),
                "scrollCapabilityCatalogSection",
                view.activeSection,
            )
        : "";
}

/** Uma pastilha desloca a lista até à secção, e não filtra: o catálogo fica todo à vista. */
function scrollCapabilityCatalogSection(event) {
    const chip = event.target.closest(
        "[data-action=\"scrollCapabilityCatalogSection\"]",
    );
    if (!chip) return;

    const target = document.getElementById(chip.dataset.section || "");
    if (!target) return;

    view.activeSection = chip.dataset.section || "";
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

function handleCapabilityCatalogSearch() {
    view.query = els.capabilityCatalogSearch.value;
    renderCapabilitiesCatalogSection();
}

function matchesCapabilityQuery(entry) {
    const needle = String(view.query || "").trim().toLowerCase();
    if (needle === "") return true;
    const label = capabilityLabel(entry.key).toLowerCase();
    return label.includes(needle) || String(entry.key).toLowerCase().includes(needle);
}

function renderCapabilitySupplierButtons() {
    const suppliers = view.suppliers;
    const selected = view.supplier;

    if (!suppliers.length) {
        els.capabilitySupplierButtons.innerHTML =
            "<div class=\"small text-secondary\">Sem fornecedores para este tipo de dispositivo.</div>";
        return;
    }

    const items = [
        { value: "", label: "Todos" },
        ...suppliers.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    els.capabilitySupplierButtons.innerHTML = buttonGroup(
        items,
        selected,
        "selectCapabilitySupplier",
    );
}

function updateCapabilitySupplierSummary() {
    if (!els.capabilitySupplierSummary) return;

    const supplierId = view.supplier;
    const suppliers = view.suppliers;
    const supplier = supplierId
        ? suppliers.find((s) => String(s.id) === String(supplierId))
        : null;

    const total = state.settingsModal.capabilityCatalog.length;
    if (supplier) {
        const supported = view.templateEnabledKeys.length;
        els.capabilitySupplierSummary.textContent =
            `${supported} de ${total} capacidades suportadas por ${supplier.name}.`;
    } else {
        els.capabilitySupplierSummary.textContent =
            `${countLabel(total, "capacidade", "capacidades")} no tipo escolhido.`;
    }
}

export {
    loadCapabilityCatalog,
    resetCapabilityCatalogView,
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
    handleCapabilityDeviceTypeClick,
    handleCapabilitySupplierClick,
    handleCapabilityCatalogSearch,
    scrollCapabilityCatalogSection,
};
