import { html } from "../../../html.js";
import { field } from "../../../components/form-field.js";
import { boolValue } from "../normalizers.js";
import { boundedNumber, enabledSwitch, entryFieldLabel, switchField } from "./shared.js";
import { readCheckbox, readNumber, readText } from "../readers.js";

/** O interruptor e, por baixo, os campos numéricos com os limites e rótulos do catálogo. */
function switchWithNumbers(entry, desired, meta, fields, extra = "") {
    return html`
        <div class="vstack gap-3">
            ${enabledSwitch(boolValue(desired.enabled, false))}
            <div class="row g-3">
                ${extra}
                ${fields.map((name) => field(entryFieldLabel(entry, meta, name), boundedNumber(entry, meta, name, desired, { step: name === "thresholdCelsius" ? 0.1 : 1 }), { cls: `col-md-${12 / Math.min(fields.length, 3)}` }))}
            </div>
        </div>`;
}

function sleepSettingsInput(entry, desired, meta) {
    const time = (name) => field(entryFieldLabel(entry, meta, name), html`<input class="form-control" type="time" data-config-field="${name}" value="${String(desired[name] ?? "")}">`, { cls: "col-md-4" });

    return html`
        <div class="vstack gap-3">
            ${enabledSwitch(boolValue(desired.enabled, false))}
            <div class="row g-3">
                ${time("startTime")}${time("endTime")}
                ${field(entryFieldLabel(entry, meta, "targetMinutes"), boundedNumber(entry, meta, "targetMinutes", desired), { cls: "col-md-4" })}
            </div>
        </div>`;
}

/** Os números que a ficha marca opcionais: um campo vazio fica fora do payload, e não vai como 0. */
const optionalNumbers = (section, names) => Object.fromEntries(
    names.filter((name) => readText(section, name) !== "").map((name) => [name, readNumber(section, name)]),
);

/** O segundo campo da definição é o limiar: em % no oxigénio, em °C na temperatura. */
const thresholdField = (entry) => entry.fields?.[1] ?? "thresholdPercent";

export const INPUTS = {
    wonlexBloodPressureWarning: {
        render: (entry, desired, meta) => switchWithNumbers(entry, desired, meta, ["systolicMmHg", "diastolicMmHg"]),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            ...optionalNumbers(section, ["systolicMmHg", "diastolicMmHg"]),
        }),
    },
    wonlexSleepSettings: {
        render: sleepSettingsInput,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            ...Object.fromEntries(["startTime", "endTime"].map((name) => [name, readText(section, name)]).filter(([, value]) => value !== "")),
            targetMinutes: readNumber(section, "targetMinutes"),
        }),
    },
    wonlexReminderThreshold: {
        render: (entry, desired, meta) => switchWithNumbers(entry, desired, meta, [thresholdField(entry)]),
        read: (section) => {
            const name = section.querySelector("input[type=\"number\"][data-config-field]")?.dataset.configField ?? "thresholdPercent";
            return { enabled: readCheckbox(section, "enabled"), ...optionalNumbers(section, [name]) };
        },
    },
    wonlexHeartRateRange: {
        render: (entry, desired, meta) => switchWithNumbers(
            entry,
            desired,
            meta,
            ["exerciseMinBpm", "exerciseMaxBpm", "exerciseThresholdBpm"],
            html`${field(entryFieldLabel(entry, meta, "thresholdBpm"), boundedNumber(entry, meta, "thresholdBpm", desired), { cls: "col-md-6" })}
                <div class="col-md-6">${switchField("exerciseEnabled", boolValue(desired.exerciseEnabled, false), { on: "Com limites de exercício", off: "Sem limites de exercício", cls: "mt-4" })}</div>`,
        ),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            exerciseEnabled: readCheckbox(section, "exerciseEnabled"),
            ...optionalNumbers(section, ["thresholdBpm", "exerciseMinBpm", "exerciseMaxBpm", "exerciseThresholdBpm"]),
        }),
    },
};
