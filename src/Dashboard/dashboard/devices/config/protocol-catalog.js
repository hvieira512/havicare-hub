import { getProtocolConfigCatalog } from "../../api/index.js";
import { state } from "../../state.js";

// Pedidos em curso, para quem pede o mesmo protocolo ao mesmo tempo partilhar um fetch.
const protocolCatalogRequests = {};

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

/** Os modos de toque que o protocolo emite; vazio quando não os declara, e vale o padrão. */
export function protocolHelpCallPressModes(protocol) {
    return protocolDefinition(protocol)?.helpCallPressModes ?? [];
}
