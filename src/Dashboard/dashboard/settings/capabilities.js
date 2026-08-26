import {
    getModelFilters as apiGetModelFilters,
    getModelTemplate as apiGetModelTemplate,
} from "../api/index.js";
import {state} from "../state.js";
import {esc} from "../format.js";
import {renderButtonGroup, renderDeviceTypeTiles} from "../widgets.js";
import {requestCardContent} from "../telemetry-cards.js";
import {ensureCapabilityCatalog} from "../capability-catalog.js";
import {
    capabilitiesGroupedBySection,
    deviceTypeOptions,
    humanizeCapabilityKey,
    normalizeDeviceType,
} from "../domain.js";

/**
 * O separador das Capacidades: o catalogo do que um tipo de dispositivo pode ter.
 *
 * E uma vista de leitura, e nao um editor -- ligar e desligar capacidades faz-se na ficha
 * de um modelo, no separador do catalogo. O que se vem aqui perguntar e o inverso: que
 * capacidades existem para este tipo, e quais delas e que este fornecedor traz. Por isso e
 * que as que ele nao declara continuam na lista, esbatidas, em vez de desaparecerem.
 *
 * O catalogo por tipo, com a sua cache, vive no `capability-catalog.js` da raiz -- a
 * coluna de detalhe tambem o le, para dar nome as capacidades nos cartoes.
 */
let els;

async function initSettingsCapabilities(context) {
    els = context.els;
}

/**
 * O catálogo do tipo escolhido, guardado onde este separador o desenha.
 *
 * A cache e o pedido vivem no `capability-catalog.js` da raiz, que a coluna de detalhe
 * também usa; aqui só se escolhe qual dos catálogos é o que está à vista.
 */
async function loadCapabilityCatalog(deviceType) {
    const catalog = await ensureCapabilityCatalog(
        deviceType || state.settingsModal.capabilityDeviceType || "watch",
    );
    state.settingsModal.capabilityCatalog = catalog;
    return catalog;
}

async function ensureCapabilityModelFilters() {
    if (
        state.settingsModal.modelFilters.length > 0 ||
        state.settingsModal.sectionLoaded.modelFilters
    ) {
        return;
    }
    const response = await apiGetModelFilters();
    state.settingsModal.modelFilters = response.data || [];
    state.settingsModal.sectionLoaded.modelFilters = true;
}

function resolveCapabilitySuppliersForDeviceType(deviceType) {
    const group = state.settingsModal.modelFilters.find(
        (g) => normalizeDeviceType(g.deviceType || "") === deviceType,
    );
    state.settingsModal.capabilitySuppliersForDeviceType = group?.suppliers || [];
}

async function loadCapabilityTemplate(supplierId, deviceType) {
    if (!supplierId || !deviceType) {
        state.settingsModal.capabilityTemplateEnabledKeys = [];
        return;
    }
    const response = await apiGetModelTemplate({ supplierId, deviceType });
    if (response.error) {
        state.settingsModal.capabilityTemplateEnabledKeys = [];
        return;
    }
    state.settingsModal.capabilityTemplateEnabledKeys = Array.isArray(
        response.enabledCapabilities,
    )
        ? response.enabledCapabilities.map(String)
        : [];
}

async function loadSettingsCapabilitiesSection(
    deviceType = state.settingsModal.capabilityDeviceType || "watch",
) {
    const normalized = normalizeDeviceType(deviceType);
    const deviceTypeChanged =
        state.settingsModal.capabilityDeviceType !== normalized;
    state.settingsModal.capabilityDeviceType = normalized;
    if (deviceTypeChanged) {
        state.settingsModal.capabilitySupplier = "";
        state.settingsModal.capabilityTemplateEnabledKeys = [];
    }
    await loadCapabilityCatalog(normalized);
    await ensureCapabilityModelFilters();
    resolveCapabilitySuppliersForDeviceType(normalized);
    if (state.settingsModal.capabilitySupplier) {
        await loadCapabilityTemplate(
            state.settingsModal.capabilitySupplier,
            normalized,
        );
    }
    state.settingsModal.sectionLoaded.capabilities = true;
    renderCapabilitiesCatalogSection();
}

function handleCapabilitySupplierClick(event) {
    const button = event.target.closest(
        '[data-action="selectCapabilitySupplier"]',
    );
    if (!button) return;
    selectCapabilitySupplier(button.dataset.value);
}

async function selectCapabilitySupplier(supplierId) {
    state.settingsModal.capabilitySupplier = supplierId || "";
    const deviceType = state.settingsModal.capabilityDeviceType || "watch";
    if (supplierId) {
        await loadCapabilityTemplate(supplierId, deviceType);
    } else {
        state.settingsModal.capabilityTemplateEnabledKeys = [];
    }
    renderCapabilitiesCatalogSection();
}

function renderCapabilitiesCatalogSection() {
    renderDeviceTypeTiles(els.capabilityDeviceTypeButtons, deviceTypeOptions, {
        selected: state.settingsModal.capabilityDeviceType || "watch",
        action: "selectCapabilityDeviceType",
    });

    const supplierId = state.settingsModal.capabilitySupplier;
    const enabledKeys = state.settingsModal.capabilityTemplateEnabledKeys;
    const enabledSet = new Set(enabledKeys);
    const hasSupplierFilter = !!supplierId && enabledKeys.length > 0;
    const supplier = supplierId
        ? state.settingsModal.capabilitySuppliersForDeviceType.find(
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
                .filter(
                    (entry) =>
                        entry.isTelemetry ||
                        entry.isConfigurable ||
                        entry.isEvent,
                )
                .filter((entry) => matchesCapabilityQuery(entry))
                // As que o fornecedor nao declara deixam de desaparecer: saber que uma
                // capacidade existe para o tipo de dispositivo e que este fornecedor nao a
                // traz e a pergunta que se vem aqui fazer.
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
    // Sem a chave: quem administra o hub nao precisa do vocabulario do protocolo, e o
    // icone -- o mesmo que a capacidade ja tem nos cartoes de pedido -- passa a ser o que
    // se reconhece. O tipo tambem sai, porque era a seccao repetida em cada linha.
    const supplierName = supplier ? supplier.name : "";

    els.capabilityCatalogViewer.innerHTML = visibleSections
        .map(({ section, label, entries }) => {
            const supported = entries.filter((entry) => entry.supported).length;
            let index = 0;

            const rows = entries
                .map((entry) => {
                    const facts = entry.supported
                        ? [
                            entry.isConfigurable ? "Configurável" : null,
                            entry.isRequestable ? "Solicitável" : null,
                        ].filter(Boolean)
                        : [supplierName ? `não oferecido pela ${supplierName}` : "não oferecido"];
                    // Numera o que e suportado, para o ultimo numero da seccao dizer
                    // quantas capacidades o dispositivo tem de facto.
                    const number = entry.supported
                        ? String(++index).padStart(2, "0")
                        : "—";
                    return `
                <div class="capability-row${entry.supported ? "" : " is-unsupported"}">
                    <span class="capability-index" aria-hidden="true">${number}</span>
                    <span class="d-flex justify-content-center text-secondary"><i class="fa-solid ${esc(capabilityIcon(entry, section))}"></i></span>
                    <span class="capability-name">${esc(entry.label || humanizeCapabilityKey(entry.key))}</span>
                    <span class="capability-facts">${esc(facts.join(" · "))}</span>
                </div>`;
                })
                .join("");

            return `
        <section id="${esc(catalogSectionId(section))}" class="capability-catalog-section">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="section-label">${esc(label)}</div>
                <span class="small text-secondary">${supported === entries.length ? `${supported} ${supported === 1 ? "capacidade" : "capacidades"}` : `${supported} de ${entries.length}`}</span>
            </div>
            <div class="vstack gap-2">${rows}</div>
        </section>
    `;
        })
        .join("");

    renderCapabilityCatalogSectionNav(visibleSections);
}

/**
 * Cinco seccoes irmas, cinco icones -- e uma cor. Eram ciano cheio, verde, navy, vermelho
 * e cinzento, como se tivessem gravidades diferentes, e o vermelho dos alarmes lia-se como
 * erro em vez de categoria.
 */
export const CAPABILITY_SECTION_ICONS = {
    telemetry: "fa-chart-line",
    health: "fa-heart-pulse",
    contacts: "fa-address-book",
    alarms: "fa-bell",
    settings_system: "fa-gear",
};

/**
 * O icone de uma capacidade no catalogo.
 *
 * O mapa dos cartoes de pedido cobre o que se pede a um dispositivo, que e sobretudo
 * telemetria. Fora disso caia tudo no mesmo `fa-circle-info`, e catorze circulos iguais
 * numa seccao de alarmes dizem menos do que icone nenhum -- por isso o recurso e o icone da
 * seccao, que ao menos e verdade.
 */
function capabilityIcon(entry, section) {
    const mapped = requestCardContent(entry.key);
    if (mapped.icon && mapped.icon !== "fa-circle-info") return mapped.icon;
    return CAPABILITY_SECTION_ICONS[section] || "fa-circle-info";
}

/** O `id` da seccao no catalogo, que e o destino das pastilhas da tira. */
function catalogSectionId(section) {
    return `capabilityCatalog-${String(section).replace(/[^a-zA-Z0-9_-]/g, "-")}`;
}

/**
 * A tira de seccoes: cada pastilha aponta para o `id` da sua seccao e leva a contagem do
 * que o fornecedor suporta, para se saber o tamanho de uma seccao antes de se ir la.
 */
function renderCapabilityCatalogSectionNav(sections) {
    if (!els.capabilityCatalogSectionNav) return;

    els.capabilityCatalogSectionNav.innerHTML = sections.length > 1
        ? sections
            .map(({ section, label, entries }) => {
                const supported = entries.filter((entry) => entry.supported).length;
                return `
            <button type="button" class="capability-section-chip" data-action="scrollCapabilityCatalogSection" data-target="${esc(catalogSectionId(section))}">
                ${esc(label)}<span class="count">${supported}</span>
            </button>`;
            })
            .join("")
        : "";
}

function handleCapabilityCatalogSearch() {
    state.settingsModal.capabilityQuery = els.capabilityCatalogSearch.value;
    renderCapabilitiesCatalogSection();
}

/** A pesquisa do catalogo: compara com o nome e com a chave, que e o que a linha mostra. */
function matchesCapabilityQuery(entry) {
    const needle = String(state.settingsModal.capabilityQuery || "").trim().toLowerCase();
    if (needle === "") return true;
    const label = String(entry.label || humanizeCapabilityKey(entry.key)).toLowerCase();
    return label.includes(needle) || String(entry.key).toLowerCase().includes(needle);
}

function renderCapabilitySupplierButtons() {
    const suppliers = state.settingsModal.capabilitySuppliersForDeviceType;
    const selected = state.settingsModal.capabilitySupplier;

    if (!suppliers.length) {
        els.capabilitySupplierButtons.innerHTML =
            '<div class="small text-secondary">Sem fornecedores para este tipo de dispositivo.</div>';
        return;
    }

    const items = [
        { value: "", label: "Todos" },
        ...suppliers.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    renderButtonGroup(
        els.capabilitySupplierButtons,
        items,
        selected,
        "selectCapabilitySupplier",
    );
}

function updateCapabilitySupplierSummary() {
    if (!els.capabilitySupplierSummary) return;

    const supplierId = state.settingsModal.capabilitySupplier;
    const suppliers = state.settingsModal.capabilitySuppliersForDeviceType;
    const supplier = supplierId
        ? suppliers.find((s) => String(s.id) === String(supplierId))
        : null;

    const total = state.settingsModal.capabilityCatalog.length;
    if (supplier) {
        const supported = state.settingsModal.capabilityTemplateEnabledKeys.length;
        els.capabilitySupplierSummary.textContent =
            `${supported} de ${total} capacidades suportadas por ${supplier.name}.`;
    } else {
        els.capabilitySupplierSummary.textContent =
            `${total} ${total === 1 ? "capacidade" : "capacidades"} no tipo escolhido.`;
    }
}

export {
    loadCapabilityCatalog,
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
    handleCapabilitySupplierClick,
    handleCapabilityCatalogSearch,
};
