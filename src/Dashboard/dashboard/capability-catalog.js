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

/** Os nomes dos eventos de protocolo; os das capacidades vêm da `label` do `/api/capabilities`. */
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

/** O catálogo de um tipo, com cache por tipo. */
const inFlightByType = new Map();

export async function ensureCapabilityCatalog(deviceType) {
    const normalized = normalizeDeviceType(deviceType || "watch");
    const cached = state.capabilityCatalogByType[normalized];
    if (cached) {
        return cached;
    }

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

/** O template de capacidades de um par fornecedor×tipo, em cache toda a sessão. */
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

function capabilityCatalogFor(deviceType) {
    return state.capabilityCatalogByType[normalizeDeviceType(deviceType || "watch")] || [];
}

/** O nome de uma capacidade ou de um evento; sem nenhum, a chave humanizada. */
export function capabilityLabel(key) {
    const deviceType = state.selectedDetail?.model?.deviceType;
    const catalog = deviceType ? capabilityCatalogFor(deviceType) : [];
    const fromCatalog = catalog.find((entry) => entry.key === key)?.label;

    return fromCatalog || PROTOCOL_EVENT_LABELS[key] || capabilityLabelByKey(key, []);
}
