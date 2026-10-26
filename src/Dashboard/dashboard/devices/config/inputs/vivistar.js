import { html } from "../../../core/html.js";
import { field } from "../../../components/form-field.js";
import { boolValue } from "../normalizers.js";
import { choiceField, entryFieldLabel, fieldChoices, numberField, readChoice, switchField } from "./shared.js";
import { readCheckbox, readNumber } from "../readers.js";

/** O tom de cada sensibilidade, da mais tolerante à mais exigente. */
const SENSITIVITY_TONES = { 1: "success", 2: "warning", 3: "danger" };

function fallSensitivityInput(entry, desired, meta) {
    return choiceField({
        field: "sensitivity",
        value: desired.sensitivity,
        options: fieldChoices(entry, meta, "sensitivity").map((option) => ({ ...option, tone: SENSITIVITY_TONES[option.value] })),
        label: entryFieldLabel(entry, meta, "sensitivity"),
    });
}

/** Uma opção que declara `fields` pede-os quando escolhida: o modo personalizado leva intervalo e GPS. */
function workingModeInput(entry, desired, meta) {
    const options = fieldChoices(entry, meta, "mode");
    const custom = options.find((option) => option.fields);
    const intervalBounds = custom?.fields?.intervalSeconds ?? {};

    return html`
        <div class="vstack gap-3">
            ${choiceField({ field: "mode", value: desired.mode, options, cards: true, label: entryFieldLabel(entry, meta, "mode") })}
            ${custom
                ? html`
                <div class="${String(desired.mode) === String(custom.value) ? "" : "d-none"}" data-working-mode-extra="${custom.value}">
                    <div class="row g-3">
                        ${field(entryFieldLabel(entry, meta, "intervalSeconds"), numberField("intervalSeconds", desired.intervalSeconds ?? intervalBounds.min ?? "", { min: intervalBounds.min ?? 0 }), { cls: "col-md-6" })}
                        <div class="col-md-6">${switchField("gpsEnabled", boolValue(desired.gpsEnabled, true), { on: "GPS ativo", off: "GPS desligado", cls: "mt-4" })}</div>
                    </div>
                </div>`
                : ""}
        </div>`;
}

/** O intervalo e o GPS só se escolhem na opção que os declara. */
export function syncWorkingModeExtra(section, mode) {
    const extra = section.querySelector("[data-working-mode-extra]");
    extra?.classList.toggle("d-none", String(mode) !== extra.dataset.workingModeExtra);
}

export const INPUTS = {
    fallSensitivity: {
        render: fallSensitivityInput,
        read: (section) => ({ sensitivity: readChoice(section, "sensitivity") }),
    },
    workingMode: {
        render: workingModeInput,
        read: (section) => {
            const mode = readChoice(section, "mode");
            const extra = section.querySelector("[data-working-mode-extra]");
            return extra && String(mode) === extra.dataset.workingModeExtra
                ? { mode, intervalSeconds: readNumber(section, "intervalSeconds"), gpsEnabled: readCheckbox(section, "gpsEnabled") }
                : { mode };
        },
    },
};
