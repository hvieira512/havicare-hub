import { html } from "../../../core/html.js";
import { field } from "../../../components/form-field.js";
import { languageLabel } from "../../../core/i18n.js";
import { boolValue } from "../normalizers.js";
import { takePillsInput, readTakePills } from "../four-p-touch-take-pills.js";
import { boundedNumber, choiceField, entryFieldLabel, fieldChoices, readChoice, switchField } from "./shared.js";
import { readCheckbox, readNumber, readText, readTextArray } from "../readers.js";

/** O tom e o ícone de cada perfil, do mais audível ao silêncio. */
const PROFILE_STYLE = {
    ring_and_vibrate: { icon: "fa-volume-high", tone: "primary" },
    ring: { icon: "fa-bell", tone: "secondary" },
    vibrate: { icon: "fa-mobile-screen-button", tone: "warning" },
    silent: { icon: "fa-volume-xmark", tone: "danger" },
};

function soundProfileInput(entry, desired, meta) {
    return choiceField({
        field: "mode",
        value: desired.mode,
        options: fieldChoices(entry, meta, "mode").map((option) => ({ ...option, ...(PROFILE_STYLE[option.value] ?? {}) })),
        label: entryFieldLabel(entry, meta, "mode"),
    });
}

/** O idioma e o fuso são dois campos: o construtor junta-os no `LZ`. */
function languageTimezoneInput(entry, desired, meta) {
    const languages = (entry.options?.language ?? [])
        .map((option) => ({ value: String(option.value), label: languageLabel(option.value) }))
        .sort((left, right) => left.label.localeCompare(right.label, "pt-PT"));
    const current = String(desired.language ?? "");

    return html`
        <div class="row g-3">
            ${field(entryFieldLabel(entry, meta, "language"), html`<select class="form-select" data-config-field="language">
                ${languages.map((option) => html`<option value="${option.value}"${option.value === current ? " selected" : ""}>${option.label}</option>`)}
            </select>`, { cls: "col-md-6" })}
            ${field(entryFieldLabel(entry, meta, "utcOffsetHours"), boundedNumber(entry, meta, "utcOffsetHours", desired, { unit: "UTC" }), { cls: "col-md-6" })}
        </div>`;
}

function dualToggleInput(_entry, desired) {
    return html`
        <div class="vstack gap-3">
            ${switchField("enabled", boolValue(desired.enabled, false))}
            ${switchField("callCenterOnFall", boolValue(desired.callCenterOnFall, false), { on: "Liga para a central", off: "Não liga para a central" })}
        </div>`;
}

/** Os níveis acima da escala do firmware ficam escondidos; 1 é a sensibilidade máxima. */
function fallSensitivityLevelsInput(entry, desired, meta) {
    const total = Number(desired.levels) || 0;
    const levels = fieldChoices(entry, meta, "levels");
    const sensitivity = fieldChoices(entry, meta, "sensitivity").map((option) => ({
        ...option,
        label: `${option.value} · ${option.label}`,
    }));

    return html`
        <div class="row g-3">
            <div class="col-12 col-md-9" data-fall-levels="${total}">
                ${field(entryFieldLabel(entry, meta, "sensitivity"), choiceField({ field: "sensitivity", value: desired.sensitivity, options: sensitivity }))}
            </div>
            ${field(entryFieldLabel(entry, meta, "levels"), html`<select class="form-select" data-config-field="levels" data-action="fallTotalLevels">
                ${levels.map((option) => html`<option value="${option.value}"${Number(option.value) === total ? " selected" : ""}>${option.label}</option>`)}
            </select>`, { cls: "col-12 col-md-3" })}
        </div>`;
}

/** Os níveis acima da escala do firmware saem da vista, e um valor fora dela passa ao maior que existe. */
export function syncFallSensitivityLevels(section) {
    const total = Number(section.querySelector("[data-config-field=\"levels\"]")?.value) || 0;
    const radios = [...section.querySelectorAll("input[type=\"radio\"][data-config-field=\"sensitivity\"]")];
    for (const radio of radios) {
        const hidden = Number(radio.value) > total;
        radio.disabled = hidden;
        section.querySelector(`label[for="${CSS.escape(radio.id)}"]`)?.classList.toggle("d-none", hidden);
    }

    const checked = radios.find((radio) => radio.checked);
    if (checked?.disabled) {
        checked.checked = false;
        radios.filter((radio) => !radio.disabled).pop()?.click();
    }
}

function timeRangesInput(entry, desired, meta) {
    const limit = Math.max(1, Number(entry.limit) || 1);
    const ranges = Array.isArray(desired.ranges) ? desired.ranges : [];

    return html`
        <div class="row row-cols-1 row-cols-sm-auto g-3">
            ${Array.from({ length: limit }, (_, index) => html`
                <div class="col">
                    <label class="form-label-sm">${entryFieldLabel(entry, meta, "ranges")} ${index + 1}</label>
                    <input class="form-control" type="text" data-config-field="ranges" value="${String(ranges[index] ?? "")}" placeholder="HH:MM-HH:MM" size="11">
                </div>`)}
        </div>`;
}

function timeRangeInput(entry, desired, meta) {
    return html`
        <div class="d-flex justify-content-end">
            <input class="form-control w-auto" type="text" data-config-field="range" value="${String(desired.range ?? "")}" placeholder="HH:MM-HH:MM" size="11" aria-label="${entryFieldLabel(entry, meta, "range")}">
        </div>`;
}

export const INPUTS = {
    soundProfile: {
        render: soundProfileInput,
        read: (section) => ({ mode: readChoice(section, "mode") }),
    },
    languageTimezone: {
        render: languageTimezoneInput,
        read: (section) => ({
            language: readText(section, "language"),
            utcOffsetHours: readNumber(section, "utcOffsetHours"),
        }),
    },
    dualToggle: {
        render: dualToggleInput,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            callCenterOnFall: readCheckbox(section, "callCenterOnFall"),
        }),
    },
    fallSensitivityLevels: {
        render: fallSensitivityLevelsInput,
        read: (section) => ({
            sensitivity: readChoice(section, "sensitivity"),
            levels: readNumber(section, "levels"),
        }),
    },
    timeRanges: {
        render: timeRangesInput,
        read: (section) => ({ ranges: readTextArray(section, "ranges") }),
    },
    timeRange: {
        render: timeRangeInput,
        read: (section) => ({ range: readText(section, "range") }),
    },
    takePills: {
        render: takePillsInput,
        read: readTakePills,
    },
};
