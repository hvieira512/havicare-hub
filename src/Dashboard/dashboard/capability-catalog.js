import {
    getCapabilities as apiGetCapabilities,
    getModelTemplate as apiGetModelTemplate,
} from "./api/index.js";
import { state } from "./state.js";
import { capabilityLabelByKey, normalizeDeviceType } from "./domain.js";

/** O ícone de cada secção de capacidades: as definições e a configuração de dispositivo usam-no. */
export const CAPABILITY_SECTION_ICONS = {
    telemetry: "fa-chart-line",
    health: "fa-heart-pulse",
    contacts: "fa-address-book",
    alarms: "fa-bell",
    reminders: "fa-clock",
    settings_system: "fa-gear",
};

/**
 * O catálogo de capacidades de cada tipo e o nome de cada uma, que vem da `label` do
 * `/api/capabilities`. Aqui só ficam os eventos de protocolo, como `device.connected`.
 */
const PROTOCOL_EVENT_LABELS = {
    alarm: "Alarme",
    "device.connected": "Ligado",
    "device.disconnected": "Desligado",
    device_config: "Configuração",
    heartbreath: "Sinais vitais",
    position: "Posições",
    reset: "Cancelada",
    unknown: "Desconhecida",
};

/**
 * O catálogo de um tipo de dispositivo, com cache por tipo e não global: são seis tipos e
 * cada ecrã só olha para um. Quem chama garante que está lá antes de desenhar.
 */
const inFlightByType = new Map();

export async function ensureCapabilityCatalog(deviceType) {
    const normalized = normalizeDeviceType(deviceType || "watch");
    const cached = state.capabilityCatalogByType[normalized];
    if (cached) {
        return cached;
    }

    // Um pedido por tipo, partilhado por quem pede ao mesmo tempo.
    if (!inFlightByType.has(normalized)) {
        inFlightByType.set(
            normalized,
            apiGetCapabilities({ deviceType: normalized })
                .then((response) => {
                    // Um erro não fica em cache: o pedido seguinte volta a tentar.
                    if (response?.error) return [];
                    state.capabilityCatalogByType[normalized] = response.data || [];
                    return state.capabilityCatalogByType[normalized];
                })
                .finally(() => inFlightByType.delete(normalized)),
        );
    }

    return inFlightByType.get(normalized);
}

const modelTemplates = new Map();

/**
 * O template de capacidades de um par fornecedor×tipo. Fica em cache toda a sessão: sai das
 * definições do fornecedor que vivem em código, e nada na dashboard o escreve.
 */
export async function ensureModelTemplate(supplierId, deviceType) {
    const normalized = normalizeDeviceType(deviceType || "watch");
    const key = `${supplierId}|${normalized}`;
    let pending = modelTemplates.get(key);
    if (!pending) {
        pending = apiGetModelTemplate({ supplierId, deviceType: normalized });
        modelTemplates.set(key, pending);
        // Um erro não fica em cache: o pedido seguinte volta a tentar.
        void pending.then((response) => {
            if (response?.error) modelTemplates.delete(key);
        });
    }

    return pending;
}

/** O catálogo já carregado de um tipo, ou vazio se ninguém o pediu ainda. */
function capabilityCatalogFor(deviceType) {
    return state.capabilityCatalogByType[normalizeDeviceType(deviceType || "watch")] || [];
}

/**
 * O nome de uma capacidade ou de um evento, para o dispositivo escolhido: o catálogo do tipo,
 * os eventos de protocolo, e por fim a chave humanizada.
 */
export function capabilityLabel(key) {
    const deviceType = state.selectedDetail?.model?.deviceType;
    const catalog = deviceType ? capabilityCatalogFor(deviceType) : [];
    const fromCatalog = catalog.find((entry) => entry.key === key)?.label;

    return fromCatalog || PROTOCOL_EVENT_LABELS[key] || capabilityLabelByKey(key, []);
}
