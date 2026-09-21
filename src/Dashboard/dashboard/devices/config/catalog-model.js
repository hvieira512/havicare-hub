import { titleize } from "../../format.js";
import { protocolGroupedCapabilities } from "./protocol-catalog.js";

/**
 * O modelo do catálogo de configuração: do catálogo cru do protocolo às secções prontas a
 * desenhar.
 *
 * Separado do desenho porque não tem interface nenhuma -- é função pura do catálogo, do
 * catálogo de capacidades e dos metadados do protocolo.
 */

const CONFIG_SECTION_ORDER = [
    "health",
    "contacts",
    "alarms",
    "settings_system",
];

/**
 * As secções do painel, já filtradas, agrupadas, ordenadas e etiquetadas.
 *
 * @returns {Array<{key: string, label: string, entries: Array<object>}>}
 */
export function configCatalogSections(protocol, catalog, capabilityCatalog) {
    const groups = groupedCatalog(
        normalizedCatalogForProtocol(protocol, catalog, capabilityCatalog),
    );

    groups.sort((a, b) => {
        const ai = CONFIG_SECTION_ORDER.indexOf(a.key);
        const bi = CONFIG_SECTION_ORDER.indexOf(b.key);
        if (ai !== bi) {
            return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
        }
        return a.key.localeCompare(b.key);
    });

    for (const group of groups) {
        group.label = group.entries[0]?.sectionLabel || titleize(group.key);
    }

    return groups;
}

function groupedCatalog(catalog) {
    const groups = [];
    const index = new Map();

    for (const entry of catalog) {
        const key = entry.category || "general";
        if (!index.has(key)) {
            index.set(key, { key, label: "", entries: [] });
            groups.push(index.get(key));
        }
        index.get(key).entries.push(entry);
    }

    return groups;
}

function normalizedCatalogForProtocol(protocol, catalog, capabilityCatalog) {
    const groupedCapabilities = protocolGroupedCapabilities(protocol);
    if (Object.keys(groupedCapabilities).length === 0) {
        return catalog
            .map((entry) => normalizeConfigEntry(entry))
            .map((entry) => assignCapabilitySection(entry, capabilityCatalog))
            .filter(Boolean);
    }

    const grouped = new Map();
    const normalized = [];

    for (const entry of catalog) {
        const nativeKey = String(entry.key || "");
        const normalizedEntry = normalizeConfigEntry(entry);
        const capabilityKey = normalizedEntry.capabilityKey || "";
        const groupedCapability = groupedCapabilities[capabilityKey] || null;
        const label = groupedCapability?.label || "";

        if (label === "") {
            normalized.push(normalizedEntry);
            continue;
        }

        if (!grouped.has(capabilityKey)) {
            grouped.set(capabilityKey, {
                ...normalizedEntry,
                key: capabilityKey,
                capabilityKey,
                label,
                input: capabilityKey,
                category: normalizedEntry.category || "contacts",
                limit: groupedCapability?.limit || 0,
                transient: false,
                configKind: "capability",
                configSectionName: "contacts",
                configKeys: [],
            });
            normalized.push(grouped.get(capabilityKey));
        }

        const groupedEntry = grouped.get(capabilityKey);
        groupedEntry.configKeys.push(nativeKey);
        groupedEntry.command = groupedEntry.configKeys.join(" · ");
    }

    return normalized
        .map((entry) => assignCapabilitySection(entry, capabilityCatalog))
        .filter(Boolean);
}

function assignCapabilitySection(entry, capabilityCatalog) {
    const capabilityKey = String(entry.capabilityKey || entry.key || "");
    const definition = capabilityDefinitionForKey(
        capabilityCatalog,
        capabilityKey,
    );
    if (!definition?.isConfigurable && !definition?.isRequestable) {
        return null;
    }

    // Uma grandeza que também se pede -- o `device_status`, que pergunta ao aparelho o estado
    // em vez de se esperar pelo próximo relatório -- vive na secção `telemetry`, que não é
    // uma secção de configuração. Pedi-la é uma acção sobre o aparelho, e é em Sistema que
    // ela cabe; sem isto ficava declarada como pedível e sem nenhum botão que a pedisse.
    const declared = String(definition.section || "");
    const section = CONFIG_SECTION_ORDER.includes(declared)
        ? declared
        : (definition.isRequestable ? "settings_system" : "");
    if (section === "") {
        return null;
    }

    return {
        ...entry,
        category: section,
        configSectionName: section,
        sectionLabel: String(definition.sectionLabel || section),
        requestOnly: definition.isRequestable && !definition.isConfigurable,
    };
}

function capabilityDefinitionForKey(capabilityCatalog, capabilityKey) {
    if (capabilityKey === "") {
        return null;
    }

    return (capabilityCatalog || []).find(
        (definition) => String(definition?.key || "") === capabilityKey,
    ) || null;
}

function normalizeConfigEntry(entry) {
    const capabilityKey = String(entry.capabilityKey || "");
    const key = capabilityKey || String(entry.key || "");
    const genericInputs = new Set([
        "alarm_clock",
        "phonebook",
        "sos_contacts",
        "call_whitelist",
        "whitelist_enabled",
    ]);
    const input = genericInputs.has(capabilityKey)
        ? capabilityKey
        : String(entry.input || "json");
    const label = capabilityKey === "alarm_clock"
        ? "Alarmes"
        : String(entry.label || key || "");
    const configKind = capabilityKey === "alarm_clock"
        ? "capability"
        : String(entry.configKind || "configuration");

    return {
        ...entry,
        key,
        input,
        label,
        capabilityKey: capabilityKey || key,
        configKind,
        configSectionName: capabilityKey === "alarm_clock" ? "alarms" : entry.configSectionName,
    };
}
