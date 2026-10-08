/**
 * O que cada tipo de dispositivo tem, vindo do `DeviceTypeCatalog` pela ilha JSON
 * `#hub-device-types`. Sem ela o módulo recusa carregar: a falha é de fiação, e não de dados.
 */
const deviceTypesIsland = globalThis.document?.getElementById("hub-device-types");
const DEVICE_TYPES = deviceTypesIsland ? JSON.parse(deviceTypesIsland.textContent) : null;
if (!DEVICE_TYPES || Object.keys(DEVICE_TYPES).length === 0) {
    throw new Error(
        "A ilha de dados #hub-device-types está vazia ou não existe: o index.php serve-a a partir do DeviceTypeCatalog",
    );
}

/** O tipo para onde cai tudo o que não se reconhece; tem de existir na tabela. */
const FALLBACK_DEVICE_TYPE = "watch";
if (!(FALLBACK_DEVICE_TYPE in DEVICE_TYPES)) {
    throw new Error(
        `config/device-types.json tem de definir "${FALLBACK_DEVICE_TYPE}": é o tipo por omissão do normalizeDeviceType`,
    );
}

export const deviceTypeOptions = Object.entries(DEVICE_TYPES).map(
    ([value, descriptor]) => ({ value, label: descriptor.label }),
);

/** A linha de um tipo, sempre utilizável, normalizada como no `normalizeDeviceType`. */
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

/**
 * O protocolo de um aparelho, pelo fornecedor e pelo modelo: a mesma marca vende TCP e BLE.
 * Sem modelo vale o primeiro do fornecedor.
 */
export function supplierProtocol(supplier, models = [], model = "") {
    const ofSupplier = (models || []).filter(
        (entry) => entry.supplier === supplier && entry.protocol,
    );

    const wanted = String(model || "").trim();
    const exact = wanted === ""
        ? null
        : ofSupplier.find((entry) => modelInternalName(entry) === wanted);

    return (exact ?? ofSupplier[0])?.protocol || "";
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

/**
 * O fornecedor à frente do nome comercial, sem o dizer duas vezes quando o nome já o traz.
 * Basta comparar o início: nenhum fornecedor é prefixo de outro.
 */
export function supplierModelLabel(supplier, commercial) {
    const brand = String(supplier || "");
    const name = String(commercial || "");
    if (brand === "" || name === "") {
        return brand || name;
    }
    return name.toLowerCase().startsWith(brand.toLowerCase())
        ? name
        : `${brand} ${name}`;
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

export function capabilitiesGroupedBySection(catalog) {
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

/** Os modos de toque de um botão de ajuda, em minúsculas porque se lêem como sufixo. */
export const PRESS_TYPE_LABEL = {
    single: "toque simples",
    double: "toque duplo",
    triple: "toque triplo",
    long: "toque longo",
};

/** O que uma queda diz: confirmada ou suspeita, e como a pessoa ficou. Sem postura é a do relógio. */
export function fallLabel(data) {
    if (data?.posture === "sitting_on_ground") return "Sentado no chão";
    if (data?.confirmed === false) return "Queda suspeita";
    return data?.posture ? "Queda confirmada" : "Queda detetada";
}

const ZONE_LABEL = {
    zone_entry: { room: "Entrou na divisão", area: "Entrou na área", geofence: "Entrou na zona segura" },
    zone_exit: { room: "Saiu da divisão", area: "Saiu da área", geofence: "Saiu da zona segura" },
};

/** Uma entrada ou saída numa frase, com a área pelo nome que lhe deram na planta. */
export function zoneLabel(type, data) {
    const label = ZONE_LABEL[type]?.[String(data?.zone || "")] || (type === "zone_exit" ? "Saiu de uma zona" : "Entrou numa zona");
    return data?.zone === "area" && data?.areaName ? `${label} «${data.areaName}»` : label;
}
