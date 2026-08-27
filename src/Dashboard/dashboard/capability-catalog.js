import {getCapabilities as apiGetCapabilities} from "./api/index.js";
import {state} from "./state.js";
import {capabilityLabelByKey, normalizeDeviceType} from "./domain.js";

/**
 * O catálogo de capacidades de cada tipo de dispositivo, e o nome por que se chama cada uma.
 *
 * O nome vem na `label` do `/api/capabilities`, que sai das definições em PHP e da base de
 * dados: é a única fonte, e um segundo mapa escrito à mão aqui discordaria dela.
 *
 * O que fica escrito aqui são os nomes que *não* são capacidades: eventos de protocolo como
 * `device.connected` ou `hbstatics`, que não são coisas que um modelo suporte ou não.
 */
const PROTOCOL_EVENT_LABELS = {
    alarm: "Alarme",
    "device.connected": "Ligado",
    "device.disconnected": "Desligado",
    device_config: "Configuração",
    hbstatics: "Estatísticas de sinais vitais por minuto",
    heartbreath: "Sinais vitais",
    minute_stats: "Estatísticas de posições por minuto",
    position: "Posições",
    reset: "Cancelada",
    unknown: "Desconhecida",
};

/**
 * O catálogo de um tipo de dispositivo, com cache por tipo e não global: são seis tipos e
 * cada ecrã só olha para um. Quem chama garante que está lá antes de desenhar.
 */
export async function ensureCapabilityCatalog(deviceType) {
    const normalized = normalizeDeviceType(deviceType || "watch");
    const cached = state.capabilityCatalogByType[normalized];
    if (cached) {
        return cached;
    }

    const response = await apiGetCapabilities({deviceType: normalized});
    const catalog = response.data || [];
    state.capabilityCatalogByType[normalized] = catalog;
    return catalog;
}

/** O catálogo já carregado de um tipo, ou vazio se ninguém o pediu ainda. */
export function capabilityCatalogFor(deviceType) {
    return state.capabilityCatalogByType[normalizeDeviceType(deviceType || "watch")] || [];
}

/**
 * O nome de uma capacidade ou de um evento, para o dispositivo que está escolhido.
 *
 * Por esta ordem: o catálogo do tipo deste dispositivo, os eventos de protocolo, e por fim
 * a chave humanizada -- que é o que sobra para uma chave que o hub ainda não conhece.
 */
export function capabilityLabel(key) {
    const deviceType = state.selectedDetail?.model?.deviceType;
    const catalog = deviceType ? capabilityCatalogFor(deviceType) : [];
    const fromCatalog = catalog.find((entry) => entry.key === key)?.label;

    return fromCatalog || PROTOCOL_EVENT_LABELS[key] || capabilityLabelByKey(key, []);
}
