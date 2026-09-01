/**
 * O que cada tipo de dispositivo tem. A tabela vive no `DeviceTypeCatalog`, em PHP, e o
 * `index.php` serve-a em `window.hubDeviceTypes`.
 *
 * Faltando ela, este módulo recusa carregar. Um valor por omissão vazio não dava erro nenhum
 * -- dava um formulário sem tipos e um `normalizeDeviceType` que devolvia sempre "watch",
 * que se lê como problema de dados quando é de fiação.
 */
const DEVICE_TYPES = globalThis.window?.hubDeviceTypes;
if (!DEVICE_TYPES || Object.keys(DEVICE_TYPES).length === 0) {
    throw new Error(
        "window.hubDeviceTypes está vazio ou não foi definido: o index.php serve-o a partir do DeviceTypeCatalog",
    );
}

/**
 * O tipo para onde cai tudo o que não se reconhece. Verificado aqui porque tirá-lo da tabela
 * passava a guarda acima e só rebentava mais à frente, com `undefined` a meio.
 */
const FALLBACK_DEVICE_TYPE = "watch";
if (!(FALLBACK_DEVICE_TYPE in DEVICE_TYPES)) {
    throw new Error(
        `config/device-types.json tem de definir "${FALLBACK_DEVICE_TYPE}": é o tipo por omissão do normalizeDeviceType`,
    );
}

export const deviceTypeOptions = Object.entries(DEVICE_TYPES).map(
    ([value, descriptor]) => ({ value, label: descriptor.label }),
);

/**
 * A linha de um tipo, sempre utilizável. Normaliza como o `normalizeDeviceType`, porque
 * repetir isso em cada chamador é como as formas divergem.
 */
export function deviceTypeFields(deviceType) {
    return DEVICE_TYPES[normalizeDeviceType(deviceType)];
}

export function linksToGateway(deviceType) {
    return deviceTypeFields(deviceType).gatewayLinks;
}

export function normalizeDeviceType(deviceType) {
    return deviceTypeOptions.some((option) => option.value === deviceType)
        ? deviceType
        : FALLBACK_DEVICE_TYPE;
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

function suppliersFromModels(models = []) {
    return [...new Set((models || []).map((model) => model.supplier).filter(Boolean))];
}

function modelsForSupplier(supplier, models = []) {
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

function capabilityCatalogEntryByKey(
    key,
    catalog = [],
) {
    return (catalog || []).find((entry) => entry.key === key) || null;
}

export function capabilityLabelByKey(key, catalog = []) {
    return capabilityCatalogEntryByKey(key, catalog)?.label || humanizeCapabilityKey(key);
}

function capabilitySectionLabel(section, catalog = []) {
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

/**
 * Como se chama cada modo de toque de um botão de ajuda.
 *
 * Lê-se como sufixo -- "chamada de ajuda (toque simples)" --, e por isso vem em minúsculas;
 * quem titula uma coluna com isto capitaliza a primeira letra.
 */
export const PRESS_TYPE_LABEL = {
    single: "toque simples",
    double: "toque duplo",
    triple: "toque triplo",
    long: "toque longo",
};

/** O que cada detecção do radar diz. */
export const DETECTION_TYPE_LABEL = {
    fall_confirmed: "Queda confirmada",
    on_floor: "No chão",
    sitting_confirmed: "Sentado no chão",
    apnea: "Apneia",
    heart_rate_high: "Frequência cardíaca alta",
    heart_rate_high_critical: "Frequência cardíaca muito alta",
    heart_rate_low: "Frequência cardíaca baixa",
    heart_rate_low_critical: "Frequência cardíaca muito baixa",
    breathing_high: "Respiração acelerada",
    breathing_low: "Respiração lenta",
    vitals_signal_lost: "Sem sinais vitais",
    room_entry: "Entrou na divisão",
    room_exit: "Saiu da divisão",
    area_entry: "Entrou na área",
    area_exit: "Saiu da área",
};
