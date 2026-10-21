import {
    getCapabilities as apiGetCapabilities,
    getModelTemplate as apiGetModelTemplate,
} from "./api/index.js";
import { state } from "./state.js";
import { normalizeDeviceType } from "./domain.js";

export { capabilityLabel } from "./i18n.js";

/** O ícone de cada secção de capacidades: as definições e a configuração de dispositivo usam-no. */
export const CAPABILITY_SECTION_ICONS = {
    telemetry: "fa-chart-line",
    health: "fa-heart-pulse",
    contacts: "fa-address-book",
    alarms: "fa-bell",
    reminders: "fa-clock",
    settings_system: "fa-gear",
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
