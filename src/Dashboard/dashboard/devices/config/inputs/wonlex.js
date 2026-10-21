import { html } from "../../../html.js";
import { field } from "../../../components/form-field.js";
import { boolValue } from "../normalizers.js";
import { enabledSwitch, numberField } from "./shared.js";
import {
    readCheckbox,
    readNumber,
    readText,
} from "../readers.js";

/** Os painéis Wonlex trazem o estado em `enabled` ou em `switchState`, conforme a geração. */
const wonlexEnabled = (desired) => boolValue(desired.enabled ?? desired.switchState, true);

function wonlexBloodPressureWarningInput(desired) {
    return html`
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            <div class="row g-3">
                ${field(
                    "Sistólica máxima (mmHg)",
                    numberField("hpWarn", desired.hpWarn ?? 135),
                    { cls: "col-md-6" },
                )}
                ${field(
                    "Diastólica máxima (mmHg)",
                    numberField("LPWarn", desired.LPWarn ?? 90),
                    { cls: "col-md-6" },
                )}
            </div>
        </div>`;
}

/** O relógio quer as horas do sono em HHmmss. */
const fromWireTime = (value) => String(value ?? "").replace(/^(\d{2})(\d{2})\d{2}$/, "$1:$2");
const toWireTime = (value) => value.replace(/^(\d{2}):(\d{2})$/, "$1$200");

function wonlexSleepSettingsInput(desired) {
    return html`
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            <div class="row g-3">
                ${field(
                    "Início",
                    html`<input class="form-control" type="time" data-config-field="sleepStartTime" value="${fromWireTime(desired.sleepStartTime ?? "220000")}">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Fim",
                    html`<input class="form-control" type="time" data-config-field="sleepEndTime" value="${fromWireTime(desired.sleepEndTime ?? "100000")}">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Meta",
                    numberField("sleepTarget", desired.sleepTarget ?? 480, { unit: "min" }),
                    { cls: "col-md-4" },
                )}
            </div>
        </div>`;
}

/** O `RemindValue` é o da temperatura, em °C; o `reminderValue` é o do oxigénio, em %. */
const isTemperatureThreshold = (entry) => (entry.fields || []).includes("RemindValue");

function wonlexReminderThresholdInput(entry, desired) {
    const temperature = isTemperatureThreshold(entry);
    const valueField = temperature ? "RemindValue" : "reminderValue";
    const value =
        desired[valueField] ??
        desired.reminderValue ??
        desired.RemindValue ??
        (temperature ? 38.5 : 90);
    return html`
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            ${field(
                temperature ? "Valor (°C)" : "Valor (%)",
                numberField(valueField, value, { step: temperature ? 0.1 : 1 }),
            )}
        </div>`;
}

/** O 120 da spec é o exemplo da alta; o alerta baixo fica por preencher. */
const isLowHeartRate = (entry) => entry.key === "wonlexHeartRateLowRemind";

function wonlexHeartRateRangeInput(entry, desired) {
    const exerciseEnabled = boolValue(
        desired.exerciseEnabled ?? desired.exerciseSwitchState,
        true,
    );
    return html`
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            <div class="row g-3">
                ${field(
                    "Limite principal (bpm)",
                    numberField("remindValue", desired.remindValue ?? (isLowHeartRate(entry) ? "" : 120)),
                    { cls: "col-md-6" },
                )}
                <div class="col-md-6">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-config-field="exerciseEnabled" ${exerciseEnabled ? "checked" : ""}>
                        <label class="form-check-label">Usar limites de exercício</label>
                    </div>
                </div>
                ${field(
                    "Mínimo exercício (bpm)",
                    numberField("exerciseHRMin", desired.exerciseHRMin ?? 100),
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Máximo exercício (bpm)",
                    numberField("exerciseHRMax", desired.exerciseHRMax ?? 140),
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Alerta em exercício (bpm)",
                    numberField("exerciseRemindValue", desired.exerciseRemindValue ?? 140),
                    { cls: "col-md-4" },
                )}
            </div>
        </div>`;
}

export const INPUTS = {
    wonlexBloodPressureWarning: {
        render: (_entry, desired) =>
            wonlexBloodPressureWarningInput(desired),
        read: (section) => ({
            // A `BPEarlyWarning` da Wonlex leva um limiar sistólico e um diastólico.
            enabled: readCheckbox(section, "enabled"),
            hpWarn: readNumber(section, "hpWarn"),
            LPWarn: readNumber(section, "LPWarn"),
        }),
        defaults: () => ({ enabled: true, hpWarn: 135, LPWarn: 90 }),
    },
    wonlexSleepSettings: {
        render: (_entry, desired) => wonlexSleepSettingsInput(desired),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            sleepStartTime: toWireTime(readText(section, "sleepStartTime")),
            sleepEndTime: toWireTime(readText(section, "sleepEndTime")),
            sleepTarget: readNumber(section, "sleepTarget"),
        }),
        defaults: () => ({
            enabled: true,
            sleepStartTime: "220000",
            sleepEndTime: "100000",
            sleepTarget: 480,
        }),
    },
    wonlexReminderThreshold: {
        render: wonlexReminderThresholdInput,
        read: (section) => {
            const valueField = section.querySelector(
                "[data-config-field=\"RemindValue\"]",
            )
                ? "RemindValue"
                : "reminderValue";
            return {
                enabled: readCheckbox(section, "enabled"),
                [valueField]: Number.parseFloat(readText(section, valueField)) || 0,
            };
        },
        defaults: (entry) => isTemperatureThreshold(entry)
            ? { enabled: true, RemindValue: 38.5 }
            : { enabled: true, reminderValue: 90 },
    },
    wonlexHeartRateRange: {
        render: wonlexHeartRateRangeInput,
        read: (section) => {
            if (readText(section, "remindValue") === "") {
                throw new Error("Indique o limite principal");
            }
            return {
                enabled: readCheckbox(section, "enabled"),
                remindValue: readNumber(section, "remindValue"),
                exerciseEnabled: readCheckbox(section, "exerciseEnabled"),
                exerciseHRMin: readNumber(section, "exerciseHRMin"),
                exerciseHRMax: readNumber(section, "exerciseHRMax"),
                exerciseRemindValue: readNumber(section, "exerciseRemindValue"),
            };
        },
        defaults: (entry) => ({
            enabled: true,
            ...(isLowHeartRate(entry) ? {} : { remindValue: 120 }),
            exerciseEnabled: true,
            exerciseHRMin: 100,
            exerciseHRMax: 140,
            exerciseRemindValue: 140,
        }),
    },
};
