import { requestJson } from "../../api/http.js";
import { state } from "../../state.js";

/**
 * The per-protocol configuration catalog and the dashboard metadata that comes
 * with it.
 *
 * Kept apart from the rendering and reading code because it is the only part
 * that talks to the API and caches: everything else is a pure function of what
 * it returns.
 */

// In-flight requests, so N sections asking for the same protocol at once share
// a single fetch rather than racing each other.
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
