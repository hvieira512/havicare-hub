import { html, raw } from "../../../html.js";
import { boolValue } from "../normalizers.js";
import { readCheckbox, readNumber } from "../readers.js";
import { enabledSwitch, nextUid, numberField } from "./shared.js";

/** Os campos que o radar declara; os nomes vêm todos da definição. */

const fieldsOf = (entry, count) => Array.from({ length: count }, (_, index) => entry.fields?.[index] ?? `value${index}`);

function readChecked(section, field) {
    return String(section.querySelector(`[data-config-field="${CSS.escape(field)}"]:checked`)?.value ?? "");
}

function readDecimal(section, field) {
    const value = parseFloat(section.querySelector(`[data-config-field="${CSS.escape(field)}"]`)?.value ?? "");
    return Number.isFinite(value) ? Math.round(value * 10) / 10 : 0;
}

function sensitivitySlider(entry, desired) {
    const [name] = fieldsOf(entry, 1);
    const { min = 1, max = 99 } = entry.options ?? {};
    const value = String(desired[name] ?? entry.options?.default ?? min);
    return html`
        <div class="d-flex align-items-center gap-3" data-twin-fields>
            <span class="small text-secondary">Baixa</span>
            <input class="form-range flex-grow-1" type="range" min="${min}" max="${max}" step="1" data-config-field="${name}" value="${value}" aria-label="${entry.label || "Sensibilidade"}">
            <span class="small text-secondary">Alta</span>
            <input class="form-control w-auto" type="number" min="${min}" max="${max}" step="1" data-config-field="${name}" value="${value}" aria-label="${entry.label || "Sensibilidade"}">
        </div>`;
}

function valueRange(entry, desired) {
    const [low, high] = fieldsOf(entry, 2);
    const { min = 0, max = "", unit = "" } = entry.options ?? {};
    const defaults = entry.options?.default ?? {};
    const input = (name, label) => html`<input class="form-control" type="number" min="${min}"${max === "" ? "" : raw(` max="${Number(max)}"`)} step="1" data-config-field="${name}" aria-label="${label}" value="${String(desired[name] ?? defaults[name] ?? min)}">`;
    return html`
        <div class="d-flex justify-content-end">
            <div class="input-group flex-nowrap w-auto">
                ${input(low, "Mínimo")}
                <span class="input-group-text">até</span>
                ${input(high, "Máximo")}
                ${unit === "" ? "" : html`<span class="input-group-text">${unit}</span>`}
            </div>
        </div>`;
}

function switchWithValue(entry, desired) {
    const [, name] = fieldsOf(entry, 2);
    const { min = 0, max = "", step = 1, unit = "" } = entry.options ?? {};
    const defaults = entry.options?.default ?? {};
    const enabled = boolValue(desired.enabled ?? defaults.enabled, false);
    return html`
        <div class="d-flex align-items-center gap-3">
            ${enabledSwitch(enabled)}
            ${numberField(name, desired[name] ?? defaults[name] ?? min, {
                min,
                max,
                step,
                unit,
                ariaLabel: entry.label || "",
                cls: `flex-nowrap w-auto ms-auto${enabled ? "" : " opacity-50"}`,
            })}
        </div>`;
}

/** Com o interruptor desligado o valor fica esbatido, mas continua a ser o que se envia. */
export function syncSwitchValueDim(section) {
    if (section?.dataset.configInput !== "switchWithValue") return;
    const enabled = section.querySelector("[data-config-field=\"enabled\"]")?.checked ?? true;
    section.querySelector(".input-group")?.classList.toggle("opacity-50", !enabled);
}

/** O padrão do `workingMode` da Vivistar, com as opções da definição e valores em texto. */
function optionCardsMarkup(name, options, current, label = "") {
    const group = nextUid(`option-${name}`);
    return html`
        <div class="row g-2" role="radiogroup"${label ? html` aria-label="${label}"` : ""}>
            ${options.map((option, index) => {
                const value = String(option.value);
                const id = `${group}-${index}`;
                return html`
                <div class="col-12 col-md-6">
                    <input class="btn-check" type="radio" name="${group}" id="${id}" data-config-field="${name}" value="${value}"${value === current ? raw(" checked") : ""}>
                    <label class="btn btn-outline-primary w-100 h-100 text-start d-flex gap-3 align-items-start p-3" for="${id}">
                        ${option.icon ? html`<i class="fa-solid ${option.icon} mt-1" aria-hidden="true"></i>` : ""}
                        <span>
                            <span class="d-block fw-semibold">${String(option.label ?? value)}</span>
                            ${option.description ? html`<span class="d-block small">${option.description}</span>` : ""}
                        </span>
                    </label>
                </div>`;
            })}
        </div>`;
}

function optionList(entry, name) {
    return Array.isArray(entry.options?.[name]) ? entry.options[name] : [];
}

function optionCards(entry, desired) {
    const [name] = fieldsOf(entry, 1);
    const options = optionList(entry, name);
    const current = String(desired[name] ?? entry.options?.default ?? options[0]?.value ?? "");
    return optionCardsMarkup(name, options, current, entry.label || "");
}

const RECOMMENDED_HEIGHTS = [
    ["Teto", "2 a 3 m"],
    ["Parede, trajetos", "1,8 a 2,3 m"],
    ["Parede, quedas", "1,5 a 1,8 m"],
    ["Cabeceira, sono", "1,5 a 1,6 m"],
];

function radarInstallation(entry, desired) {
    const [mounting, height] = fieldsOf(entry, 2);
    const options = optionList(entry, mounting);
    const defaults = entry.options?.default ?? {};
    const bounds = entry.options?.[height] ?? {};
    const current = String(desired[mounting] ?? defaults[mounting] ?? options[0]?.value ?? "");
    return html`
        <div class="vstack gap-2">
            ${optionCardsMarkup(mounting, options, current, "Montagem")}
            <details class="small text-secondary">
                <summary>Alturas recomendadas</summary>
                <ul class="mb-0 mt-1 ps-3">
                    ${RECOMMENDED_HEIGHTS.map(([where, range]) => html`<li>${where}: ${range}</li>`)}
                    <li>No modo Sono o radar assume 1,5 m.</li>
                </ul>
            </details>
            <div class="d-flex align-items-center justify-content-end gap-2">
                <span>Altura</span>
                ${numberField(height, desired[height] ?? defaults[height] ?? bounds.min ?? 0, {
                    min: bounds.min ?? 0,
                    max: bounds.max ?? "",
                    step: bounds.step ?? 1,
                    unit: "cm",
                    ariaLabel: "Altura",
                    cls: "flex-nowrap w-auto",
                })}
            </div>
        </div>`;
}

const BOUNDARY_LABELS = { leftM: "Esquerda", rightM: "Direita", frontM: "Frente", backM: "Trás" };

function radarBoundary(entry, desired) {
    const [left, right, front, back] = fieldsOf(entry, 4);
    const step = entry.options?.step ?? 0.1;
    const factory = entry.options?.default ?? entry.options?.ceiling ?? {};
    const cell = (name) => html`
        <div class="d-flex flex-column align-items-center gap-1">
            <span class="small text-secondary">${BOUNDARY_LABELS[name] ?? name}</span>
            ${numberField(name, desired[name] ?? factory[name] ?? 0, { min: 0, step, unit: "m", ariaLabel: BOUNDARY_LABELS[name] ?? name, cls: "flex-nowrap radar-boundary-field" })}
        </div>`;
    return html`
        <div class="radar-boundary" data-boundary-fields="${JSON.stringify([left, right, front, back])}" data-boundary-factory="${JSON.stringify({ ceiling: entry.options?.ceiling ?? {}, wall: entry.options?.wall ?? {} })}">
            <div class="radar-boundary-front">${cell(front)}</div>
            <div class="radar-boundary-left">${cell(left)}</div>
            <div class="radar-boundary-radar" aria-hidden="true"><i class="fa-solid fa-satellite-dish"></i></div>
            <div class="radar-boundary-right">${cell(right)}</div>
            <div class="radar-boundary-back">${cell(back)}</div>
        </div>`;
}

/** Mudar a montagem repõe no radar os limites de fábrica dela; o cartão dos limites acompanha. */
export function applyFactoryBoundary(pane, mounting) {
    const box = pane?.querySelector("[data-config-input=\"radarBoundary\"] [data-boundary-factory]");
    if (!box) return;
    let factory;
    try {
        factory = JSON.parse(box.dataset.boundaryFactory)[mounting];
    } catch {
        return;
    }
    for (const [name, value] of Object.entries(factory ?? {})) {
        const input = box.querySelector(`[data-config-field="${CSS.escape(name)}"]`);
        if (input) input.value = String(value);
    }
}

export function areasSummary(items) {
    if (items.length === 0) return "Sem áreas definidas.";
    const names = items.map((item) => String(item.name ?? "")).filter(Boolean).join(", ");
    return `${items.length} ${items.length === 1 ? "área" : "áreas"}${names ? `: ${names}` : ""}`;
}

function radarAreas(_entry, desired) {
    const items = Array.isArray(desired?.items) ? desired.items : [];
    return html`
        <div class="d-flex align-items-center gap-3" data-radar-areas="${JSON.stringify(items)}">
            <span class="flex-grow-1 min-w-0 small">${areasSummary(items)}</span>
            <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" data-action="openRadarAreas">Abrir a planta</button>
        </div>`;
}

function readAreas(section) {
    try {
        const items = JSON.parse(section.querySelector("[data-radar-areas]")?.dataset.radarAreas ?? "[]");
        return { items: Array.isArray(items) ? items : [] };
    } catch {
        return { items: [] };
    }
}

export const INPUTS = {
    sensitivitySlider: {
        render: sensitivitySlider,
        read: (section) => {
            const name = section.querySelector("[data-config-field]")?.dataset.configField ?? "sensitivity";
            return { [name]: readNumber(section, name) };
        },
        defaults: (entry) => ({ [fieldsOf(entry, 1)[0]]: entry.options?.default ?? entry.options?.min ?? 1 }),
    },
    valueRange: {
        render: valueRange,
        read: (section) => {
            const [low, high] = [...section.querySelectorAll("[data-config-field]")].map((node) => node.dataset.configField);
            const payload = { [low]: readNumber(section, low), [high]: readNumber(section, high) };
            if (payload[low] > payload[high]) {
                throw new Error(`${section.dataset.configLabel || "Intervalo"}: o mínimo não pode passar do máximo`);
            }
            return payload;
        },
        defaults: (entry) => ({ ...(entry.options?.default ?? {}) }),
    },
    switchWithValue: {
        render: switchWithValue,
        read: (section) => {
            const name = section.querySelector("input[type=\"number\"][data-config-field]")?.dataset.configField ?? "value";
            return { enabled: readCheckbox(section, "enabled"), [name]: readNumber(section, name) };
        },
        defaults: (entry) => ({ ...(entry.options?.default ?? {}) }),
    },
    optionCards: {
        render: optionCards,
        read: (section) => {
            const name = section.querySelector("[data-config-field]")?.dataset.configField ?? "value";
            return { [name]: readChecked(section, name) };
        },
        defaults: (entry) => {
            const [name] = fieldsOf(entry, 1);
            return { [name]: entry.options?.default ?? optionList(entry, name)[0]?.value ?? "" };
        },
    },
    radarInstallation: {
        render: radarInstallation,
        read: (section) => {
            const mounting = section.querySelector("input[type=\"radio\"][data-config-field]")?.dataset.configField ?? "mounting";
            const height = section.querySelector("input[type=\"number\"][data-config-field]")?.dataset.configField ?? "heightCm";
            return { [mounting]: readChecked(section, mounting), [height]: readNumber(section, height) };
        },
        defaults: (entry) => ({ ...(entry.options?.default ?? {}) }),
    },
    radarBoundary: {
        render: radarBoundary,
        // Pela ordem das definições e não pela do ecrã, que é a da cruz.
        read: (section) => Object.fromEntries(
            JSON.parse(section.querySelector("[data-boundary-fields]")?.dataset.boundaryFields ?? "[]")
                .map((name) => [name, readDecimal(section, name)]),
        ),
        defaults: (entry) => ({ ...(entry.options?.default ?? entry.options?.ceiling ?? {}) }),
    },
    radarAreas: {
        render: radarAreas,
        read: readAreas,
        defaults: () => ({ items: [] }),
    },
};
