/**
 * O dicionário pt-PT de tudo o que a dashboard escreve sobre o catálogo, chaveado pelas chaves e
 * enumerações inglesas da API. Vem da ilha `#hub-i18n`, que o `index.php` lê do `pt-PT.json`.
 */
const island = globalThis.document?.getElementById("hub-i18n");
const PT = island ? JSON.parse(island.textContent) : null;
if (!PT) {
    throw new Error("A ilha de dados #hub-i18n está vazia ou não existe: o index.php serve-a a partir do pt-PT.json");
}

export const DICTIONARY = PT;

/** O que se mostra no lugar de uma chave sem tradução: nunca a chave inglesa. */
export const UNTRANSLATED = "Sem tradução";

export const sectionLabel = (section) => PT.sections[section] ?? UNTRANSLATED;

export const capabilityLabel = (key) => PT.capabilities[key] ?? PT.events[key] ?? UNTRANSLATED;

export const verbLabel = (verb) => PT.verbs[verb] ?? UNTRANSLATED;

/** O nome de um pedido: pelo `requestId` quando o mesmo `feature` tem dois, senão pela capacidade ou configuração. */
export const commandLabel = (command) =>
    PT.commands[command?.requestId] ?? capabilityLabel(command?.feature || command?.capability || command?.genericConfigKey);

export const fieldLabel = (field) => PT.fields[field] ?? UNTRANSLATED;

/** O valor de uma enumeração de leitura; `undefined` quando o campo não é enumeração conhecida. */
export const valueLabel = (field, value) => PT.values[field]?.[String(value)];

/**
 * O texto de uma configuração, com o que o protocolo tem de seu por cima: o mesmo reinício
 * deixa um relógio sem comunicar e limpa a avaria do prato no dispensador.
 */
export function configText(capabilityKey, protocol = "") {
    const base = PT.configurations[capabilityKey] ?? {};
    const own = PT.protocols[protocol]?.[capabilityKey] ?? {};
    const merged = (part) => ({ ...(base[part] ?? {}), ...(own[part] ?? {}) });
    const nested = (part) => Object.fromEntries(
        [...new Set([...Object.keys(base[part] ?? {}), ...Object.keys(own[part] ?? {})])]
            .map((field) => [field, { ...(base[part]?.[field] ?? {}), ...(own[part]?.[field] ?? {}) }]),
    );

    return {
        label: own.label ?? base.label ?? capabilityLabel(capabilityKey),
        help: own.help ?? base.help ?? "",
        confirm: own.confirm ?? base.confirm ?? "",
        unit: own.unit ?? base.unit ?? "",
        fields: merged("fields"),
        options: nested("options"),
        descriptions: nested("descriptions"),
    };
}

/** O nome de uma opção de configuração; sem tradução, o número fica à vista em vez da chave. */
export function optionLabel(capabilityKey, protocol, field, value) {
    return configText(capabilityKey, protocol).options[field]?.[String(value)] ?? UNTRANSLATED;
}

export const optionDescription = (capabilityKey, protocol, field, value) =>
    configText(capabilityKey, protocol).descriptions[field]?.[String(value)] ?? "";

/** A etiqueta de um campo de uma configuração, a dela primeiro e a geral depois. */
export const configFieldLabel = (capabilityKey, protocol, field) =>
    configText(capabilityKey, protocol).fields[field] ?? fieldLabel(field);

const plural = new Intl.PluralRules("pt-PT");

/** «1 alarme», «3 alarmes»: as formas de cada número, pela regra do português. */
export const countLabel = (count, one, other) =>
    `${new Intl.NumberFormat("pt-PT").format(count)} ${plural.select(count) === "one" ? one : other}`;

const weekdayFormat = new Intl.DateTimeFormat("pt-PT", { weekday: "short", timeZone: "UTC" });

/** O dia da semana de 1 (segunda) a 7 (domingo), nas três letras do costume: «Seg», «Sáb». */
export const weekdayLabel = (day) => {
    const name = weekdayFormat.format(Date.UTC(2024, 0, Number(day)));
    return name.charAt(0).toUpperCase() + name.slice(1, 3);
};

const languageNames = new Intl.DisplayNames("pt-PT", { type: "language" });

export const languageLabel = (tag) => {
    const name = languageNames.of(tag) ?? UNTRANSLATED;
    return name.charAt(0).toUpperCase() + name.slice(1);
};
