import {
    getModelFilters as apiGetModelFilters,
} from "../api/index.js";
import {state} from "../state.js";
import {esc} from "../format.js";
import {renderButtonGroup, renderDeviceTypeTiles} from "../widgets.js";
import {cardIcon} from "../telemetry-cards.js";
import {ensureCapabilityCatalog, ensureModelTemplate} from "../capability-catalog.js";
import {
    capabilitiesGroupedBySection,
    deviceTypeOptions,
    humanizeCapabilityKey,
    normalizeDeviceType,
} from "../domain.js";

/**
 * O separador das Capacidades: o catálogo do que um tipo de dispositivo pode ter.
 *
 * É uma vista de leitura e não um editor -- ligar e desligar capacidades faz-se na ficha de
 * um modelo. O que se vem aqui perguntar é o inverso: que capacidades existem para este
 * tipo, e quais delas é que este fornecedor traz. Daí as que ele não declara ficarem na
 * lista, esbatidas, em vez de desaparecerem.
 */
let els;

async function initSettingsCapabilities(context) {
    els = context.els;
}

/**
 * O catálogo do tipo escolhido. A cache e o pedido vivem no `capability-catalog.js` da
 * raiz, que a coluna de detalhe também usa; aqui só se escolhe qual está à vista.
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
    const response = await ensureModelTemplate(supplierId, deviceType);
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
                // As que o fornecedor não declara ficam na lista: saber que uma capacidade
                // existe para o tipo e que este fornecedor não a traz é a pergunta que se
                // vem aqui fazer.
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
    // Sem a chave nem o tipo: quem administra o hub não precisa do vocabulário do
    // protocolo, e o ícone -- o mesmo dos cartões de pedido -- é o que se reconhece.
    const supplierName = supplier ? supplier.name : "";

    // Em telefone cada secção abre e fecha, e o catálogo é reconstruído a cada tecla da
    // pesquisa: sem isto, escrever uma letra fechava tudo o que estivesse aberto.
    const openBodies = new Set(
        Array.from(els.capabilityCatalogViewer.querySelectorAll(".collapse.show"))
            .map((body) => body.id),
    );

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
                    // Numera o que é suportado, para o último número da secção dizer
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

            // Em telefone cada secção fecha, porque as cinco somam seis ecrãs de lista
            // corrida. O `d-sm-block` ganha ao `display: none` do collapse, por isso a
            // partir de `sm` estão todas abertas e não há nada para clicar.
            const bodyId = `${catalogSectionId(section)}Body`;
            const count = supported === entries.length
                ? `${supported} ${supported === 1 ? "capacidade" : "capacidades"}`
                : `${supported} de ${entries.length}`;

            return `
        <section id="${esc(catalogSectionId(section))}" class="capability-catalog-section">
            <button type="button"
                class="d-flex justify-content-between align-items-center gap-2 w-100 mb-2 p-0 py-1 border-0 bg-transparent text-start"
                data-bs-toggle="collapse" data-bs-target="#${esc(bodyId)}"
                aria-expanded="false" aria-controls="${esc(bodyId)}">
                <span class="section-label">${esc(label)}</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="small text-secondary">${count}</span>
                    <i class="fa-solid fa-chevron-down small text-secondary d-sm-none" aria-hidden="true"></i>
                </span>
            </button>
            <div class="collapse d-sm-block" id="${esc(bodyId)}">
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

/**
 * Cinco secções irmãs, cinco ícones -- e uma cor só: cores diferentes leem-se como
 * gravidades diferentes, e o vermelho dos alarmes lia-se como erro em vez de categoria.
 */
export const CAPABILITY_SECTION_ICONS = {
    telemetry: "fa-chart-line",
    health: "fa-heart-pulse",
    contacts: "fa-address-book",
    alarms: "fa-bell",
    settings_system: "fa-gear",
};

/**
 * O ícone de uma capacidade no catálogo. O mapa dos cartões de pedido cobre sobretudo
 * telemetria; fora disso o recurso é o ícone da secção, porque catorze círculos iguais numa
 * secção de alarmes dizem menos do que ícone nenhum.
 */
function capabilityIcon(entry, section) {
    const mapped = cardIcon(entry.key);
    if (mapped !== "fa-circle-info") return mapped;
    return CAPABILITY_SECTION_ICONS[section] || "fa-circle-info";
}

/** O `id` da secção no catálogo, que é o destino das pastilhas da tira. */
function catalogSectionId(section) {
    return `capabilityCatalog-${String(section).replace(/[^a-zA-Z0-9_-]/g, "-")}`;
}

/**
 * A tira de secções: cada pastilha aponta para o `id` da sua secção e leva a contagem do
 * que o fornecedor suporta, para se saber o tamanho de uma secção antes de se ir lá.
 */
function renderCapabilityCatalogSectionNav(sections) {
    if (!els.capabilityCatalogSectionNav) return;

    // A pastilha acesa sai do estado e não de uma classe escrita à mão no DOM: a tira é
    // reconstruída pela pesquisa, e o realce escrito à mão não sobrevivia a isso.
    const active = state.settingsModal.activeCapabilityCatalogSection;

    els.capabilityCatalogSectionNav.innerHTML = sections.length > 1
        ? sections
            .map(({ section, label, entries }) => {
                const supported = entries.filter((entry) => entry.supported).length;
                const target = catalogSectionId(section);
                return `
            <button type="button" class="capability-section-chip${target === active ? " selected" : ""}" data-action="scrollCapabilityCatalogSection" data-target="${esc(target)}">
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

/** A pesquisa do catálogo: compara com o nome e com a chave. */
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
