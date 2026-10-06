import { html } from "../../../html.js";
import { field } from "../../../components/form-field.js";
import { addAlarmButton, alarmDisclosure, shortDate } from "../alarm-fields.js";
import {
    MEDICATION_CONDITIONS,
    MEDICATION_DOSE_UNITS,
    MEDICATION_MEAL_TIMINGS,
    MEDICATION_PERIODS,
    WONLEX_MEDICATION_PERIODS,
    boolValue,
    numericValue,
    defaultWonlexMedicationPlan,
    normalizeWonlexMedicationPlan,
    normalizeWonlexMedicationPlans,
} from "../normalizers.js";
import { enabledSwitch, nextUid, numberField } from "./shared.js";
import {
    readCheckbox,
    readNumber,
    readText,
} from "../readers.js";

/**
 * Os campos que só a Wonlex declara: quase tudo vem empacotado em `deviceConfig` e
 * `deviceMeasuringFrequency`, e cada limiar leva o seu formulário.
 */

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

function wonlexMedicationPlansInput(desired) {
    const plans = normalizeWonlexMedicationPlans(desired);
    if (plans.length === 0) {
        plans.push(defaultWonlexMedicationPlan());
    }

    const group = nextUid("wonlex-medication-group");

    return html`
        <div class="vstack gap-3">
            <div class="small"><span class="text-danger" aria-hidden="true">*</span> Campo obrigatório</div>
            <div class="vstack gap-2" data-repeat-list="wonlexMedicationPlan" data-repeat-limit="${WONLEX_MEDICATION_PLAN_LIMIT}">
                ${plans.slice(0, WONLEX_MEDICATION_PLAN_LIMIT).map((plan, index) => wonlexMedicationPlanRow(plan, index, group))}
            </div>
            ${addAlarmButton("wonlexMedicationPlan", "Acrescentar medicamento", Math.min(plans.length, WONLEX_MEDICATION_PLAN_LIMIT), WONLEX_MEDICATION_PLAN_LIMIT)}
        </div>`;
}

/** O relógio guarda dez planos; um décimo primeiro escreveria por cima de um existente. */
const WONLEX_MEDICATION_PLAN_LIMIT = 10;

const WONLEX_MEDICATION_UNITS = [
    ["0", "Comprimido / unidade"],
    ["1", "Ampola"],
    ["2", "ml"],
    ["3", "mg"],
    ["4", "UI"],
    ["5", "Outra"],
];

export function wonlexMedicationPlanRow(plan = {}, index = 0, group = "wonlex-medication") {
    const normalized = normalizeWonlexMedicationPlan(plan);
    const rowId = nextUid("wonlex-medication");
    const body = html`
            <div class="row g-3">
                ${field(
                    "Tipo",
                    html`<select class="form-select" data-medication-field="drugType" required>
                        ${[
                            [0, "Hipertensão"],
                            [1, "Diabetes"],
                            [2, "Colesterol / lípidos"],
                            [3, "Ácido úrico elevado"],
                        ].map(([value, label]) => html`
                            <option value="${value}" ${normalized.drugType === value ? "selected" : ""}>${label}</option>
                        `)}
                    </select>`,
                    { cls: "col-md-4", required: true },
                )}
                ${field(
                    "Nome do medicamento",
                    html`<input class="form-control" type="text" data-medication-field="drugName" value="${normalized.drugName}" placeholder="Ex.: Losartan" required>`,
                    { cls: "col-md-8", required: true },
                )}
                ${field(
                    "Dose",
                    html`<input class="form-control" type="number" min="0" step="0.1" data-medication-field="drugDose" value="${(String(normalized.drugDose))}">`,
                    { cls: "col-sm-6 col-md-3" },
                )}
                ${field(
                    "Unidade",
                    html`<select class="form-select" data-medication-field="drugUnit">
                        ${WONLEX_MEDICATION_UNITS.map(([value, label]) => html`
                            <option value="${value}" ${normalized.drugUnit === value ? "selected" : ""}>${label}</option>
                        `)}
                    </select>`,
                    { cls: "col-sm-6 col-md-3" },
                )}
                ${field(
                    "Data inicial",
                    html`<input class="form-control" type="date" data-medication-field="drugStartTime" value="${normalized.drugStartTime}" required>`,
                    { cls: "col-sm-6 col-md-3", required: true },
                )}
                ${field(
                    "Data final",
                    html`<input class="form-control" type="date" data-medication-field="drugEndTime" value="${normalized.drugEndTime}" required>`,
                    { cls: "col-sm-6 col-md-3", required: true },
                )}
                ${field(
                    "Intervalo",
                    html`<div class="input-group">
                        <input class="form-control" type="number" min="0" step="0.5" data-medication-field="drugInterval" value="${(String(normalized.drugInterval))}" required>
                        <span class="input-group-text">dias</span>
                    </div>`,
                    { cls: "col-sm-6 col-md-4", required: true },
                )}
                ${field(
                    "Tomar",
                    html`<div class="btn-group" role="group" aria-label="Relação com a refeição">
                        ${[
                            [0, "Antes da refeição"],
                            [1, "Depois da refeição"],
                        ].map(([value, label]) => {
                            const id = `${rowId}-meal-${value}`;
                            return html`
                                <input class="btn-check" type="radio" name="${rowId}-meal" id="${id}" value="${value}" data-medication-field="mealTiming" ${normalized.mealTiming === value ? "checked" : ""}>
                                <label class="btn btn-outline-secondary" for="${id}">${label}</label>
                            `;
                        })}
                    </div>`,
                    { cls: "col-sm-6 col-md-8", required: true },
                )}
            </div>
            <div class="mt-3">
                <label class="form-label-sm required">Períodos e horários</label>
                <div class="row g-2">
                    ${WONLEX_MEDICATION_PERIODS.map((period) => {
                        const selected = normalized.periods.includes(period.index);
                        const inputId = `${rowId}-period-${period.index}`;
                        return html`
                            <div class="col-sm-6 col-xl-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="${inputId}" value="${period.index}" data-medication-period ${selected ? "checked" : ""}>
                                        <label class="form-check-label" for="${inputId}">${period.label}</label>
                                    </div>
                                    <input class="form-control form-control-sm" type="time" data-medication-period-time="${period.index}" value="${normalized.alarmClock[period.key] || period.defaultTime}" ${selected ? "" : "disabled"}>
                                </div>
                            </div>
                        `;
                    })}
                </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow" title="Remover medicamento" aria-label="Remover medicamento">
                    <i class="fa-solid fa-trash-can me-2"></i>Remover
                </button>
            </div>`;

    const times = WONLEX_MEDICATION_PERIODS
        .filter((period) => normalized.periods.includes(period.index))
        .map((period) => normalized.alarmClock[period.key] || period.defaultTime)
        .sort();
    const unit = WONLEX_MEDICATION_UNITS.find(([value]) => value === normalized.drugUnit)?.[1] ?? "";
    const dose = String(normalized.drugDose ?? "").trim();

    return alarmDisclosure({
        kind: "wonlexMedicationPlan",
        group,
        body,
        summary: {
            title: [normalized.drugName, dose === "" ? "" : `${dose} ${unit}`.trim()]
                .filter(Boolean).join(" ") || "Medicamento por preencher",
            subtitle: [times.join(" · "), normalized.drugEndTime === "" ? "" : `até ${shortDate(normalized.drugEndTime)}`]
                .filter(Boolean).join(" · "),
            trailing: times.length === 0 ? "" : `${times.length}×/dia`,
            badges: "",
        },
    });
}

/** O número de cada medicamento é a sua posição na lista, e remover um a meio desalinha-os. */
export function renumberWonlexMedicationPlans(section) {
    section
        ?.querySelectorAll("[data-repeat-row=\"wonlexMedicationPlan\"]")
        .forEach((row, index) => {
            const number = row.querySelector("[data-medication-plan-number]");
            if (number) {
                number.textContent = String(index + 1);
            }
        });
}

/**
 * A hora de um período só se edita com o período escolhido, e nasce às 08:00 para não ficar
 * vazia -- uma hora em branco é recusada na leitura.
 */
export function syncWonlexMedicationPeriod(checkbox) {
    const row = checkbox.closest("[data-repeat-row=\"wonlexMedicationPlan\"]");
    const periodTime = row?.querySelector(
        `[data-medication-period-time="${checkbox.value}"]`,
    );
    if (!periodTime) return;

    periodTime.disabled = !checkbox.checked;
    if (checkbox.checked && String(periodTime.value || "") === "") {
        periodTime.value = "08:00";
    }
}

function readWonlexMedicationPlans(section) {
    const plans = Array.from(
        section.querySelectorAll("[data-repeat-row=\"wonlexMedicationPlan\"]"),
    ).map((row, index) => {
        const value = (field) => String(
            row.querySelector(`[data-medication-field="${field}"]`)?.value || "",
        ).trim();
        const drugName = value("drugName");
        const start = value("drugStartTime");
        const end = value("drugEndTime");
        if (drugName === "") {
            throw new Error(`Medicamento ${index + 1}: indique o nome`);
        }
        if (start === "" || end === "") {
            throw new Error(`Medicamento ${index + 1}: indique as datas inicial e final`);
        }
        if (end < start) {
            throw new Error(`Medicamento ${index + 1}: a data final não pode ser anterior à inicial`);
        }

        const selected = Array.from(
            row.querySelectorAll("[data-medication-period]:checked"),
        ).map((input) => parseInt(String(input.value), 10));
        if (selected.length === 0) {
            throw new Error(`Medicamento ${index + 1}: selecione pelo menos um período`);
        }

        const alarmClock = {};
        for (const periodIndex of selected) {
            const period = WONLEX_MEDICATION_PERIODS.find(
                (candidate) => candidate.index === periodIndex,
            );
            const time = String(
                row.querySelector(`[data-medication-period-time="${periodIndex}"]`)?.value || "",
            ).trim();
            if (!period || time === "") {
                throw new Error(`Medicamento ${index + 1}: indique a hora de cada período selecionado`);
            }
            alarmClock[period.key] = time;
        }

        const dose = numericValue(value("drugDose"), 0);
        const interval = numericValue(value("drugInterval"), -1);
        if (dose < 0 || interval < 0) {
            throw new Error(`Medicamento ${index + 1}: dose e intervalo não podem ser negativos`);
        }

        const mealValue = parseInt(String(
            row.querySelector("[data-medication-field=\"mealTiming\"]:checked")?.value || "0",
        ), 10);
        const meal = mealValue === 1 ? 1 : 0;

        return {
            name: drugName,
            condition: MEDICATION_CONDITIONS[parseInt(value("drugType"), 10) || 0],
            doseCount: dose,
            doseUnit: MEDICATION_DOSE_UNITS[parseInt(value("drugUnit"), 10)] ?? "other",
            startDate: start,
            endDate: end,
            intervalDays: interval,
            mealTiming: MEDICATION_MEAL_TIMINGS[meal],
            times: selected.map((periodIndex) => ({
                time: alarmClock[WONLEX_MEDICATION_PERIODS[periodIndex].key],
                enabled: true,
                period: MEDICATION_PERIODS[periodIndex],
                recurrence: { kind: "daily" },
            })),
        };
    });

    return { plans };
}

/** Cada tipo de campo declara as suas faces juntas: desenhar, ler, valor inicial e legenda. */
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
    wonlexMedicationPlans: {
        render: (_entry, desired) => wonlexMedicationPlansInput(desired),
        read: (section) => readWonlexMedicationPlans(section),
        defaults: () => ({ plans: [defaultWonlexMedicationPlan()] }),
    },
};
