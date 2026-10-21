import { html, raw } from "../../../html.js";
import { fieldUnit } from "../../../format.js";
import { configFieldLabel, configText, optionDescription, optionLabel } from "../../../i18n.js";
import { boolValue } from "../normalizers.js";

/** Só tem de ser único dentro da página. */
let uidCounter = 0;

export function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}

/** O texto de uma entrada do catálogo, pelo protocolo em que ela vive. */
export const entryText = (entry, meta = {}) => configText(entry.capabilityKey || entry.key, meta.protocol || "");

export const entryFieldLabel = (entry, meta = {}, field = "") =>
    configFieldLabel(entry.capabilityKey || entry.key, meta.protocol || "", field);

/** Os limites de um campo: os da definição, os do campo nas opções e os `bounds`, por esta ordem. */
export function fieldBounds(entry, field) {
    const options = entry.options ?? {};
    const scalar = (name) => (typeof options[name] === "number" ? { [name]: options[name] } : {});
    const own = options[field] && !Array.isArray(options[field]) && typeof options[field] === "object" ? options[field] : {};

    return { ...scalar("min"), ...scalar("max"), ...scalar("step"), ...own, ...(entry.bounds?.[field] ?? {}) };
}

/** As escolhas de um campo, com o nome que o dicionário lhes dá. */
export function fieldChoices(entry, meta = {}, field = "") {
    const key = entry.capabilityKey || entry.key;
    const declared = Array.isArray(entry.options?.[field]) ? entry.options[field] : meta[field]?.options ?? [];

    return declared.map((option) => ({
        ...option,
        label: optionLabel(key, meta.protocol || "", field, option.value),
        description: optionDescription(key, meta.protocol || "", field, option.value),
    }));
}

/** O `ariaLabel` serve os cartões sem rótulo visível, que têm o nome no título da linha. */
export const numberField = (
    configField,
    value,
    { min = 0, max = "", step = 1, ariaLabel = "", unit = fieldUnit(configField), cls = "" } = {},
) => {
    const maxAttr = max === "" ? "" : html` max="${max}"`;
    const ariaAttr = ariaLabel === "" ? "" : html` aria-label="${ariaLabel}"`;
    const input = html`<input class="form-control" type="number" min="${min}"${maxAttr} step="${step}" data-config-field="${configField}"${ariaAttr} value="${String(value ?? "")}">`;

    return unit === ""
        ? input
        : html`<div class="input-group${cls ? ` ${cls}` : ""}">${input}<span class="input-group-text">${unit}</span></div>`;
};

/** Um campo numérico com os limites e o rótulo que o catálogo e o dicionário lhe dão. */
export const boundedNumber = (entry, meta = {}, field = "", desired = {}, extra = {}) =>
    numberField(field, desired?.[field] ?? "", {
        ...fieldBounds(entry, field),
        ariaLabel: entryFieldLabel(entry, meta, field),
        ...extra,
    });

/** O interruptor de todos os cartões: o rótulo diz o estado, ou o que se escolher para cada um. */
export function switchField(field, checked, { on = "Ligado", off = "Desligado", cls = "", attr = "data-config-field" } = {}) {
    return html`
        <div class="form-check form-switch${cls ? ` ${cls}` : ""}">
            <input class="form-check-input" type="checkbox" role="switch" ${raw(attr)}="${field}" ${checked ? "checked" : ""}>
            <label class="form-check-label" data-switch-label data-switch-on="${on}" data-switch-off="${off}">${checked ? on : off}</label>
        </div>`;
}

export const enabledSwitch = (enabled, cls = "") => switchField("enabled", enabled, { cls });

/**
 * A escolha de uma lista, em rádios `btn-check`: em grupo de botões, ou em cartões quando as
 * opções têm descrição. O valor é o do rádio marcado, e o `name` único por página.
 */
export function choiceField({ field, value, options, label = "", cards = false, attr = "data-config-field" }) {
    const name = nextUid(`choice-${field}`);
    const current = String(value ?? "");
    const choices = options.map((option, index) => {
        const id = `${name}-${index}`;
        const optionValue = String(option.value);
        const input = html`<input type="radio" class="btn-check" name="${name}" id="${id}" value="${optionValue}" ${raw(attr)}="${field}"${raw(optionValue === current ? " checked" : "")}>`;
        const icon = option.icon ? html`<i class="fa-solid ${option.icon}${cards ? " mt-1" : " me-2"}" aria-hidden="true"></i>` : "";
        if (!cards) {
            return html`${input}<label class="btn btn-outline-${option.tone || "secondary"}" for="${id}">${icon}${option.label}</label>`;
        }

        return html`
            <div class="col-12 col-md-6">
                ${input}
                <label class="btn btn-outline-primary w-100 h-100 text-start d-flex gap-3 align-items-start p-3" for="${id}">
                    ${icon}
                    <span>
                        <span class="d-block fw-semibold">${option.label}</span>
                        ${option.description ? html`<span class="d-block small">${option.description}</span>` : ""}
                    </span>
                </label>
            </div>`;
    });
    const aria = label === "" ? "" : html` aria-label="${label}"`;

    return cards
        ? html`<div class="row g-2" role="radiogroup"${aria}>${choices}</div>`
        : html`<div class="btn-group flex-wrap" role="radiogroup"${aria}>${choices}</div>`;
}

/** O valor do rádio marcado; número quando o parece, que as escolhas dos aparelhos são números. */
export function readChoice(section, field, attr = "data-config-field") {
    const raw = String(section.querySelector(`[${attr}="${CSS.escape(field)}"]:checked`)?.value ?? "");
    return raw !== "" && !Number.isNaN(Number(raw)) ? Number(raw) : raw;
}

/** Um interruptor e o intervalo dele, sem rótulo: o nome está na linha de cima. */
export function intervalToggle(entry, desired, meta = {}, field = "") {
    return html`
        <div class="d-flex align-items-center gap-3">
            ${enabledSwitch(boolValue(desired.enabled, false))}
            ${boundedNumber(entry, meta, field, desired, { cls: "flex-nowrap w-auto ms-auto" })}
        </div>`;
}

export function syncSwitchLabel(input) {
    const label = input.parentElement?.querySelector("[data-switch-label]");
    if (!label) return;

    label.textContent = input.checked
        ? label.dataset.switchOn || "Ligado"
        : label.dataset.switchOff || "Desligado";
}

/** Um perfil preenche vários campos de uma vez; escrever num campo à mão desfaz a escolha. */
export function applyConfigPreset(section, radio) {
    let preset;
    try {
        preset = JSON.parse(radio.dataset.configPreset);
    } catch {
        return;
    }

    for (const [field, value] of Object.entries(preset || {})) {
        const input = section.querySelector(`input[type="number"][data-config-field="${field}"]`);
        if (input) input.value = String(value);
    }
}

/** Põe os dois pontos à medida que se escreve num campo `data-time-format="24h"`. */
export function normalizeTwentyFourHourTimeInput(input) {
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    const digits = String(input.value || "").replace(/[^0-9]/g, "").slice(0, 4);
    if (digits.length === 0) {
        input.value = "";
        return;
    }
    if (digits.length <= 2) {
        input.value = digits;
        return;
    }
    input.value = `${digits.slice(0, 2)}:${digits.slice(2)}`;
}
