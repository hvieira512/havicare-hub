import { getProtocolConfigCatalog } from "../../api/index.js";
import { state } from "../../state.js";

// Pedidos em curso, para quem pede o mesmo protocolo ao mesmo tempo partilhar um fetch.
const protocolCatalogRequests = {};

function isPlainObject(value) {
    return typeof value === "object" && value !== null && !Array.isArray(value);
}

export async function catalogForProtocol(protocol) {
    if (!protocol) {
        return [];
    }

    const protocolMeta = protocolDefinition(protocol);
    if (protocolMeta && protocolMeta.supportsConfigCatalog === false) {
        return [];
    }

    if (state.protocolCatalogs[protocol]) {
        return state.protocolCatalogs[protocol];
    }

    if (protocolCatalogRequests[protocol]) {
        return protocolCatalogRequests[protocol];
    }

    protocolCatalogRequests[protocol] = (async () => {
        const response = await getProtocolConfigCatalog(protocol);
        const catalog = Array.isArray(response.data) ? response.data : [];
        state.protocolCatalogs[protocol] = catalog;
        return catalog;
    })().finally(() => {
        delete protocolCatalogRequests[protocol];
    });

    return protocolCatalogRequests[protocol];
}

function protocolDefinition(protocol) {
    return (state.protocols || []).find((entry) => entry.protocol === protocol) || null;
}

function protocolDashboardMeta(protocol) {
    const meta = protocolDefinition(protocol)?.dashboard || {};
    return {
        groupedCapabilities: isPlainObject(meta.groupedCapabilities) ? meta.groupedCapabilities : null,
        fieldConstraints: isPlainObject(meta.fieldConstraints) ? meta.fieldConstraints : null,
        helpCallPressModes: Array.isArray(meta.helpCallPressModes) ? meta.helpCallPressModes : null,
    };
}

export function protocolGroupedCapabilities(protocol) {
    return protocolDashboardMeta(protocol).groupedCapabilities || {};
}

export function protocolFieldConstraints(protocol) {
    return protocolDashboardMeta(protocol).fieldConstraints || {};
}

/** Os modos de toque que o protocolo emite; vazio quando não os declara, e vale o padrão. */
export function protocolHelpCallPressModes(protocol) {
    return protocolDashboardMeta(protocol).helpCallPressModes || [];
}

export function protocolPhonebookConstraints(protocol) {
    return protocolFieldConstraints(protocol).phonebook || {};
}
