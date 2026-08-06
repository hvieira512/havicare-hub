export const deviceTypeOptions = [
    { value: "watch", label: "Relógio" },
    { value: "ncs", label: "NCS" },
    { value: "radar", label: "Radar" },
    { value: "gateway", label: "Gateway" },
    { value: "diaper_sensor", label: "Medidor de fraldas" },
];

export function normalizeDeviceType(deviceType) {
    return deviceTypeOptions.some((option) => option.value === deviceType)
        ? deviceType
        : "watch";
}

export function deviceTypeLabel(deviceType) {
    return (
        deviceTypeOptions.find((option) => option.value === deviceType)
            ?.label || deviceType
    );
}

export function normalizeLicenseId(licenseId) {
    const value = String(licenseId ?? "0").trim();
    return value === "" ? "0" : value;
}

export function licenseLabel(licenseId) {
    return normalizeLicenseId(licenseId) === "0"
        ? "Sem Licença"
        : normalizeLicenseId(licenseId);
}

export function companyLabel(company) {
    const value = String(company ?? "").trim();
    return value === "" || value === "null" ? "Sem empresa" : value;
}

export function licenseDisplayLabel(
    licenseId,
    licenses = [],
) {
    const normalized = normalizeLicenseId(licenseId);
    if (normalized === "0") {
        return "Sem Licença";
    }

    const match = (licenses || []).find(
        (item) =>
            String(item.license_id || item.licenseId || "") === normalized,
    );
    if (!match) {
        return normalized;
    }

    const name = String(match.name || "").trim();
    return name !== "" ? `${name} (${normalized})` : normalized;
}

export function supplierProtocol(supplier, models = []) {
    const existing = (models || []).find(
        (model) => model.supplier === supplier && model.protocol,
    );
    return existing?.protocol || "";
}

export function modelInternalName(model) {
    return String(
        model?.internal_model || model?.internalModel || model?.model || "",
    );
}

export function modelCommercialName(model) {
    return String(
        model?.commercial_name ||
            model?.commercialName ||
            model?.internal_model ||
            model?.internalModel ||
            model?.model ||
            "",
    );
}

export function modelDeviceType(model) {
    return normalizeDeviceType(
        model?.device_type || model?.deviceType || "watch",
    );
}

export function suppliersFromModels(models = []) {
    return [...new Set((models || []).map((model) => model.supplier).filter(Boolean))];
}

export function modelsForSupplier(supplier, models = []) {
    return (models || []).filter((model) => model.supplier === supplier);
}

export function findModelInfo(supplier, model, models = []) {
    return (
        (models || []).find(
            (entry) =>
                entry.supplier === supplier &&
                modelInternalName(entry) === model,
        ) || null
    );
}

export function modelDisplayName(supplier, model, models = []) {
    const info = findModelInfo(supplier, model, models);
    return info ? modelCommercialName(info) : model;
}

export function modelsForSupplierAndType(
    supplier,
    deviceType,
    models = [],
) {
    return modelsForSupplier(supplier, models).filter(
        (model) => modelDeviceType(model) === normalizeDeviceType(deviceType),
    );
}

export function modelDisplayLabel(model) {
    const commercialName = modelCommercialName(model);
    const internalName = modelInternalName(model);
    return commercialName === internalName
        ? commercialName
        : `${commercialName} (${internalName})`;
}

export function deriveFourPTouchDeviceId(imei) {
    const digits = String(imei || "").replace(/\D+/g, "");
    if (digits.length === 15) return digits.slice(4, 14);
    if (digits.length === 10) return digits;
    if (digits.length > 10) return digits.slice(-10);
    return digits;
}

export function isFourPTouchSelection(
    supplier = "",
    model = "",
    models = [],
) {
    return (
        supplierProtocol(supplier, models) === "four-p-touch" ||
        supplier === "4P Touch" ||
        model === "4P Touch"
    );
}

export function humanizeCapabilityKey(value) {
    return String(value || "")
        .replace(/_/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export function flattenedCapabilityKeys(capabilities) {
    const enabled = [];
    for (const entries of Object.values(capabilities || {})) {
        if (!entries || typeof entries !== "object") {
            continue;
        }
        for (const [key, supported] of Object.entries(entries)) {
            if (supported) {
                enabled.push(key);
            }
        }
    }
    return enabled;
}

export function capabilitiesForSupplier(supplier, models = []) {
    const entry = (models || []).find(
        (model) =>
            model.supplier === supplier &&
            model?.capabilities &&
            typeof model.capabilities === "object",
    );
    return flattenedCapabilityKeys(entry?.capabilities || {});
}

export function suppliersForDeviceType(deviceType, models = []) {
    const normalizedDeviceType = normalizeDeviceType(deviceType);
    const allSuppliers = suppliersFromModels(models);
    const deviceTypeSuppliers = (models || [])
        .filter(
            (model) =>
                modelDeviceType(model) === normalizedDeviceType,
        )
        .map((model) => model.supplier)
        .filter(Boolean);
    return allSuppliers.filter((name) => deviceTypeSuppliers.includes(name));
}

export function capabilityCatalogEntryByKey(
    key,
    catalog = [],
) {
    return (catalog || []).find((entry) => entry.key === key) || null;
}

export function capabilityLabelByKey(key, catalog = []) {
    return capabilityCatalogEntryByKey(key, catalog)?.label || humanizeCapabilityKey(key);
}

export function capabilitySectionLabel(section, catalog = []) {
    const label = (catalog || []).find((entry) => entry.section === section)?.sectionLabel;
    return label || humanizeCapabilityKey(section);
}

export function capabilitiesGroupedBySection(catalog = []) {
    const grouped = new Map();
    for (const entry of catalog || []) {
        const section = String(entry.section || "").trim();
        if (!section) {
            continue;
        }
        if (!grouped.has(section)) {
            grouped.set(section, []);
        }
        grouped.get(section).push(entry);
    }

    return [...grouped.entries()].map(([section, entries]) => ({
        section,
        label: capabilitySectionLabel(section, catalog),
        entries,
    }));
}
