import { sectionLabel } from "./i18n.js";

/** O que cada tipo de dispositivo tem, da ilha JSON `#hub-device-types`; sem ela o módulo não carrega. */
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

/** Com o protocolo, o que ele corrige: a pulseira PST é Wi-Fi e não tem MAC nem gateways. */
export function deviceTypeFields(deviceType, protocol = "") {
    const fields = DEVICE_TYPES[normalizeDeviceType(deviceType)];
    return { ...fields, ...(fields.byProtocol?.[protocol] || {}) };
}

export function linksToGateway(deviceType, protocol = "") {
    return deviceTypeFields(deviceType, protocol).gatewayLinks;
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

/** O nome que cabe num mosaico estreito; sem `shortLabel` no catálogo, é o nome inteiro. */
export function deviceTypeShortLabel(deviceType) {
    return DEVICE_TYPES[deviceType]?.shortLabel || deviceTypeLabel(deviceType);
}

export function companyLabel(company) {
    const value = String(company ?? "").trim();
    return value === "" ? "Sem empresa" : value;
}

export function licenseDisplayLabel(
    licenseId,
    licenses = [],
) {
    const normalized = String(licenseId ?? "").trim();
    if (normalized === "") {
        return "Sem Licença";
    }

    const match = (licenses || []).find(
        (item) =>
            String(item.licenseId ?? "") === normalized,
    );
    if (!match) {
        return normalized;
    }

    const name = String(match.name || "").trim();
    return name !== "" ? `${name} (${normalized})` : normalized;
}

/** O protocolo pelo fornecedor e pelo modelo, porque a mesma marca vende TCP e BLE; sem modelo, o primeiro. */
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
        model?.internalModel || model?.model || "",
    );
}

export function modelCommercialName(model) {
    return String(
        model?.commercialName ||
        model?.internalModel ||
        model?.model ||
        "",
    );
}

export function modelDeviceType(model) {
    return normalizeDeviceType(
        model?.deviceType || "watch",
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

/** O fornecedor à frente do nome comercial, sem o dizer duas vezes quando o nome já o traz. */
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
        label: sectionLabel(section),
        entries,
    }));
}

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
