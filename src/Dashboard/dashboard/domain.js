/**
 * O que cada tipo de dispositivo tem. A tabela vive no `DeviceTypeCatalog`, em PHP, e o
 * `index.php` serve-a numa ilha JSON `#hub-device-types`.
 *
 * Faltando ela, este módulo recusa carregar. Um valor por omissão vazio não dava erro nenhum
 * -- dava um formulário sem tipos e um `normalizeDeviceType` que devolvia sempre "watch",
 * que se lê como problema de dados quando é de fiação.
 */
const deviceTypesIsland = globalThis.document?.getElementById("hub-device-types");
const DEVICE_TYPES = deviceTypesIsland ? JSON.parse(deviceTypesIsland.textContent) : null;
if (!DEVICE_TYPES || Object.keys(DEVICE_TYPES).length === 0) {
    throw new Error(
        "A ilha de dados #hub-device-types está vazia ou não existe: o index.php serve-a a partir do DeviceTypeCatalog",
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

/**
 * O protocolo de um aparelho, pelo fornecedor e pelo modelo.
 *
 * Um fornecedor pode vender coisas que falam protocolos diferentes: os relógios da Wonlex
 * falam TCP e a pulseira MF91 da mesma marca fala BLE. Sem o modelo, ganhava o primeiro da
 * lista, e o painel de configurações da pulseira mostrava o catálogo dos relógios.
 *
 * O modelo é opcional: onde ele não se conhece -- o assistente de registo, antes de o
 * escolher -- continua a valer o primeiro do fornecedor, que é o que lá estava.
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

/**
 * O tom de cada postura em hexadecimal, para quem desenha fora do CSS.
 *
 * A planta da divisão é uma tela e não marcação, e por isso não lhe chegam as classes do
 * Bootstrap. São os mesmos tons das pastilhas: uma pessoa deitada não pode ser azul no mapa e
 * verde no cartão que está ao lado dele no ecrã.
 */
const TONE_HEX = {
    success: "#198754",
    info: "#0dcaf0",
    warning: "#ffc107",
    danger: "#dc3545",
    secondary: "#6c757d",
};

/**
 * A postura de uma pessoa vista por um radar: o ícone, o tom e o glifo.
 *
 * O `icon` é a classe do Font Awesome para a marcação e o `glyph` é o mesmo ícone em ponto de
 * código, que é o que a tela precisa -- vêm em par de propósito, para o mapa e a pastilha não
 * poderem divergir. Os pontos de código são os do Font Awesome 6 Free que o hub serve; o
 * `` da antena é a versão livre do ícone do radar.
 *
 * A etiqueta não está aqui: vive no `FIELD_VALUE_LABELS.posture` do `format.js`.
 */
const POSTURE_STYLE = {
    standing: { icon: "fa-person", glyph: "", tone: "success" },
    walking: { icon: "fa-person-walking", glyph: "", tone: "success" },
    confirmed_sitting_up_bed: { icon: "fa-bed", glyph: "", tone: "success" },
    lying_down: { icon: "fa-bed", glyph: "", tone: "info" },
    sitting_up_bed: { icon: "fa-bed", glyph: "", tone: "info" },
    suspected_sitting_up_bed: { icon: "fa-bed", glyph: "", tone: "warning" },
    squatting: { icon: "fa-chair", glyph: "", tone: "warning" },
    suspected_sitting_on_ground: { icon: "fa-chair", glyph: "", tone: "warning" },
    suspected_fall: { icon: "fa-triangle-exclamation", glyph: "", tone: "warning" },
    confirmed_sitting_on_ground: { icon: "fa-chair", glyph: "", tone: "danger" },
    fall_confirmation: { icon: "fa-triangle-exclamation", glyph: "", tone: "danger" },
    initialization: { icon: "fa-question", glyph: "?", tone: "secondary" },
    unknown: { icon: "fa-question", glyph: "?", tone: "secondary" },
};

/** Uma postura que o firmware invente cai na desconhecida em vez de deixar o ecrã sem nada. */
export function postureStyle(posture) {
    const style = POSTURE_STYLE[String(posture)] || POSTURE_STYLE.unknown;

    return { ...style, color: TONE_HEX[style.tone] };
}

/** As cores do fabricante para cada tipo de área declarada no aparelho. */
const AREA_TYPE_STYLE = {
    1: { label: "Personalizada", color: "#a9a9a9" },
    2: { label: "Cama", color: "#20c997" },
    3: { label: "Interferência", color: "#808080" },
    4: { label: "Porta", color: "#ffa500" },
    5: { label: "Cama de monitorização", color: "#32cd32" },
    6: { label: "Região de alarme", color: "#ff4500" },
};

/** Um tipo que o fabricante acrescente fica cinzento e com o número à vista, em vez de sumir. */
export function areaTypeStyle(type) {
    return AREA_TYPE_STYLE[Number(type)] || { label: `Tipo ${type}`, color: "#a9a9a9" };
}

/** A legenda da planta: os tipos conhecidos, mais o cinzento das outras regiões. */
export function areaLegend() {
    return [
        ...Object.values(AREA_TYPE_STYLE),
        { label: "Outras regiões", color: "#a9a9a9" },
    ];
}
