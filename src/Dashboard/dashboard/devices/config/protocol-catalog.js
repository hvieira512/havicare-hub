import { requestJson } from "../../api/http.js";
import { state } from "../../state.js";

/**
 * O catálogo de configuração de cada protocolo, e os metadados da dashboard que vêm com ele.
 *
 * Separado do código que desenha e que lê porque é a única parte que fala com a API e que
 * tem cache: o resto é função pura do que isto devolve.
 */

// Pedidos em curso, para N secções que peçam o mesmo protocolo ao mesmo tempo partilharem
// um fetch em vez de correrem umas contra as outras.
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
        const response = await requestJson(
            `/api/protocols/${encodeURIComponent(protocol)}/config-catalog`,
        );
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
    };
}

export function protocolGroupedCapabilities(protocol) {
    return protocolDashboardMeta(protocol).groupedCapabilities || {};
}

export function protocolFieldConstraints(protocol) {
    return protocolDashboardMeta(protocol).fieldConstraints || {};
}

export function protocolPhonebookConstraints(protocol) {
    return protocolFieldConstraints(protocol).phonebook || {};
}
