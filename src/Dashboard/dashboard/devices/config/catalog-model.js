import { configText, sectionLabel } from "../../i18n.js";

const CONFIG_SECTION_ORDER = [
    "health",
    "contacts",
    "alarms",
    "reminders",
    "settings_system",
];

/** As secções do painel, já filtradas, agrupadas, ordenadas e com nome. */
export function configCatalogSections(protocol, catalog, capabilityCatalog) {
    const groups = new Map();
    for (const entry of mergedByCapability(protocol, catalog)) {
        const placed = withSection(entry, capabilityCatalog);
        if (!placed) continue;
        if (!groups.has(placed.category)) {
            groups.set(placed.category, { key: placed.category, label: sectionLabel(placed.category), entries: [] });
        }
        groups.get(placed.category).entries.push(placed);
    }

    return [...groups.values()].sort((a, b) =>
        CONFIG_SECTION_ORDER.indexOf(a.key) - CONFIG_SECTION_ORDER.indexOf(b.key));
}

/**
 * Uma capacidade escrita em várias entradas nativas -- a lista de chamadas do 4P Touch vem em dois
 * grupos de cinco -- é um cartão só, com os limites somados.
 */
function mergedByCapability(protocol, catalog) {
    const merged = new Map();
    for (const entry of catalog) {
        const capabilityKey = String(entry.capabilityKey || entry.key || "");
        const existing = merged.get(capabilityKey);
        if (existing) {
            existing.configKeys.push(entry.key);
            existing.limit = (existing.limit ?? 0) + (entry.limit ?? 0);
            continue;
        }
        merged.set(capabilityKey, {
            ...entry,
            key: capabilityKey,
            capabilityKey,
            label: configText(capabilityKey, protocol).label,
            configKeys: [entry.key],
        });
    }

    return [...merged.values()];
}

function withSection(entry, capabilityCatalog) {
    const definition = (capabilityCatalog || []).find((candidate) => candidate?.key === entry.capabilityKey);
    if (!definition?.isConfigurable && !definition?.isRequestable) {
        return null;
    }

    // Uma grandeza pedível de `telemetry` não tem secção de configuração: cabe em Sistema.
    const section = CONFIG_SECTION_ORDER.includes(definition.section)
        ? definition.section
        : (definition.isRequestable ? "settings_system" : "");
    if (section === "") {
        return null;
    }

    return {
        ...entry,
        category: section,
        requestOnly: definition.isRequestable && !definition.isConfigurable,
    };
}
