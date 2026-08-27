export const deviceTypeOptions = [
    { value: "watch", label: "Relógio" },
    { value: "ncs", label: "NCS" },
    { value: "radar", label: "Radar" },
    { value: "gateway", label: "Gateway" },
    { value: "diaper_sensor", label: "Medidor de fraldas" },
    { value: "bracelet", label: "Pulseira" },
];

const MAC_IDENTITY = {
    field: "deviceId",
    label: "MAC",
    help: "Endereço MAC canónico, sem separadores (12 caracteres hexadecimais).",
    placeholder: "d48c49f7909c",
};

/**
 * O que cada tipo de dispositivo tem, numa linha por tipo -- e não em predicados aqui,
 * cadeias de `if` no modal e visibilidade espalhada por `classList.toggle`, que é como
 * acrescentar um tipo passava a obrigar a encontrar três sítios.
 *
 * `identity` é o campo que identifica a unidade e o que se lhe escreve ao lado. `sim`
 * diz se há número de SIM. `gatewayLinks` diz se o dispositivo é retransmitido por um
 * gateway em vez de falar por conta própria.
 *
 * ponytail: isto duplica o que o hub já sabe nas definições de capacidades por tipo, em
 * PHP. A saída certa é a API servir o descritor, como já serve as capacidades; consolidar
 * primeiro num só sítio no frontend é o que torna essa migração numa substituição de
 * tabela por chamada, em vez de uma caça a ramificações.
 */
const DEVICE_TYPES = {
    watch: {
        identity: {
            field: "imei",
            label: "IMEI",
            help: "15 dígitos, como vem impresso no dispositivo.",
            placeholder: "861265061009822",
        },
        sim: true,
        gatewayLinks: false,
    },
    ncs: {
        identity: {
            field: "deviceId",
            label: "Device ID (MAC)",
            help: "MAC address do dispositivo NCS (ex.: bea6c3dd8e02). Obrigatório.",
            placeholder: "MAC address (ex.: bea6c3dd8e02)",
        },
        sim: false,
        gatewayLinks: false,
    },
    radar: {
        identity: {
            field: "deviceId",
            label: "Device ID",
            help: "Identificador do dispositivo radar no protocolo.",
            placeholder: "ID do dispositivo",
        },
        sim: false,
        gatewayLinks: false,
    },
    gateway: {
        identity: MAC_IDENTITY,
        sim: false,
        gatewayLinks: false,
    },
    diaper_sensor: {
        identity: MAC_IDENTITY,
        sim: false,
        gatewayLinks: true,
    },
    bracelet: {
        identity: MAC_IDENTITY,
        sim: false,
        gatewayLinks: true,
    },
};

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
