import {state} from "../../state.js";
import {esc} from "../../format.js";
import {filterChips, renderButtonGroup} from "../../renderers.js";
import {
    deviceTypeLabel,
    deviceTypeOptions,
    normalizeDeviceType,
} from "../../domain.js";
import {loadSettingsModelsSection} from "./list.js";
import {getSettingsModelsRuntime} from "./runtime.js";

const ACTIVE_MODEL_FILTERS = [
    {
        key: "deviceType",
        label: (value) => `Tipo: ${deviceTypeLabel(value)}`,
        stateKey: "modelsDeviceType",
    },
    {
        key: "supplier",
        label: (value) => `Fornecedor: ${value}`,
        stateKey: "modelsSupplier",
    },
    {
        key: "search",
        label: (value) => `Pesquisa: ${value}`,
        stateKey: "modelsSearchQuery",
    },
];

const ACTIVE_MODEL_FILTER_CLEARERS = {
    deviceType: () => {
        state.settingsModal.modelsDeviceType = "";
    },
    supplier: () => {
        state.settingsModal.modelsSupplier = "";
    },
    search: (els) => {
        state.settingsModal.modelsSearchQuery = "";
        if (els.modelsListSearch) {
            els.modelsListSearch.value = "";
        }
    },
};

function renderAppliedModelsFilters() {
    const {els} = getSettingsModelsRuntime();
    const labels = ACTIVE_MODEL_FILTERS.flatMap((filter) => {
        const value = state.settingsModal[filter.stateKey];
        if (!value) return [];
        return [{key: filter.key, label: filter.label(value)}];
    });

    els.modelsActiveFilters.innerHTML = filterChips(labels, "removeModelsFilter");
    els.modelsFilterCount.textContent = labels.length ? String(labels.length) : "";
    els.modelsFilterCount.classList.toggle("d-none", labels.length === 0);
    els.clearModelsFiltersBtn.classList.toggle("d-none", labels.length === 0);
}

function renderModelsFilterButtons() {
    const {els} = getSettingsModelsRuntime();
    const filters = state.settingsModal.modelFilters || [];
    const deviceTypeToSuppliers = new Map();
    const supplierToDeviceTypes = new Map();
    const deviceTypes = [];

    for (const entry of filters) {
        const deviceType = normalizeDeviceType(
            entry?.deviceType || entry?.device_type || "watch",
        );
        if (!deviceTypes.includes(deviceType)) {
            deviceTypes.push(deviceType);
        }

        const suppliers = Array.isArray(entry?.suppliers)
            ? entry.suppliers
            : [];
        deviceTypeToSuppliers.set(
            deviceType,
            suppliers
                .map((supplier) => String(supplier?.name || ""))
                .filter(Boolean),
        );

        for (const supplier of suppliers) {
            const supplierName = String(supplier?.name || "").trim();
            if (supplierName === "") {
                continue;
            }
            const list = supplierToDeviceTypes.get(supplierName) || [];
            if (!list.includes(deviceType)) {
                list.push(deviceType);
            }
            supplierToDeviceTypes.set(supplierName, list);
        }
    }

    const selectedSupplierTypes = state.settingsModal.modelsSupplier
        ? supplierToDeviceTypes.get(state.settingsModal.modelsSupplier) || []
        : [];
    const selectedDeviceTypeSuppliers = state.settingsModal.modelsDeviceType
        ? deviceTypeToSuppliers.get(state.settingsModal.modelsDeviceType) || []
        : [];

    const deviceTypeOptionsFiltered = state.settingsModal.modelsSupplier
        ? deviceTypeOptions.filter((option) =>
              selectedSupplierTypes.includes(option.value),
          )
        : deviceTypeOptions.filter(
              (option) =>
                  deviceTypes.length === 0 ||
                  deviceTypes.includes(option.value),
          );

    const supplierNames = state.settingsModal.modelsDeviceType
        ? selectedDeviceTypeSuppliers
        : [...supplierToDeviceTypes.keys()];
    const supplierOptionsFiltered = supplierNames.map((name) => ({
        value: name,
        label: name,
    }));

    renderButtonGroup(
        els.modelsDeviceTypeButtons,
        deviceTypeOptionsFiltered.map((option) => ({
            value: option.value,
            label: option.label,
        })),
        state.settingsModal.modelsDeviceType || "",
        "selectModelsDeviceType",
    );
    renderButtonGroup(
        els.modelsSupplierButtons,
        supplierOptionsFiltered,
        state.settingsModal.modelsSupplier,
        "selectModelsSupplier",
    );
    renderAppliedModelsFilters();
}

function selectModelsDeviceType(deviceType) {
    state.settingsModal.modelsDeviceType = deviceType;
    state.settingsModal.modelsPage = 1;
    const filters = state.settingsModal.modelFilters || [];
    const currentSuppliers =
        filters.find(
            (entry) =>
                normalizeDeviceType(
                    entry?.deviceType || entry?.device_type || "watch",
                ) === deviceType,
        )?.suppliers || [];
    const allowedSuppliers = new Set(
        currentSuppliers
            .map((supplier) => String(supplier?.name || ""))
            .filter(Boolean),
    );
    if (
        state.settingsModal.modelsSupplier &&
        !allowedSuppliers.has(state.settingsModal.modelsSupplier)
    ) {
        state.settingsModal.modelsSupplier = "";
    }
    renderModelsFilterButtons();
    void loadSettingsModelsSection(1);
}

function selectModelsSupplier(supplier) {
    state.settingsModal.modelsSupplier = supplier;
    state.settingsModal.modelsPage = 1;
    const filters = state.settingsModal.modelFilters || [];
    const allowedDeviceTypes = filters
        .filter(
            (entry) =>
                Array.isArray(entry?.suppliers) &&
                entry.suppliers.some(
                    (item) => String(item?.name || "") === supplier,
                ),
        )
        .map((entry) =>
            normalizeDeviceType(
                entry?.deviceType || entry?.device_type || "watch",
            ),
        );
    if (
        state.settingsModal.modelsDeviceType &&
        !allowedDeviceTypes.includes(state.settingsModal.modelsDeviceType)
    ) {
        state.settingsModal.modelsDeviceType = "";
    }
    renderModelsFilterButtons();
    void loadSettingsModelsSection(1);
}

function clearModelsFilters() {
    const {els} = getSettingsModelsRuntime();
    state.settingsModal.modelsDeviceType = "";
    state.settingsModal.modelsSupplier = "";
    state.settingsModal.modelsSearchQuery = "";
    state.settingsModal.modelsPage = 1;
    if (els.modelsListSearch) {
        els.modelsListSearch.value = "";
    }
    renderModelsFilterButtons();
    void loadSettingsModelsSection(1);
}

function handleActiveModelsFiltersClick(event) {
    const {els} = getSettingsModelsRuntime();
    const button = event.target.closest('[data-action="removeModelsFilter"]');
    if (!button) return;

    const key = button.dataset.filterKey;
    if (!key) return;

    const clearFilter = ACTIVE_MODEL_FILTER_CLEARERS[key];
    if (!clearFilter) {
        return;
    }
    clearFilter(els);

    state.settingsModal.modelsPage = 1;
    renderModelsFilterButtons();
    void loadSettingsModelsSection(1);
}

export {
    clearModelsFilters,
    handleActiveModelsFiltersClick,
    renderAppliedModelsFilters,
    renderModelsFilterButtons,
    selectModelsDeviceType,
    selectModelsSupplier,
};
