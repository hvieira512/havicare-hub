import { esc, fieldLabel } from "../../../format.js";
import { field } from "../../../components/form-field.js";
import {
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
 * Os campos que só a Wonlex declara.
 *
 * São doze dos quarenta tipos de campo e valiam cerca de um terço do antigo `inputs.js`. A
 * Wonlex empacota quase tudo em `deviceConfig` e `deviceMeasuringFrequency`, e por isso cada
 * limiar precisa do seu próprio formulário em vez de um campo numérico solto.
 */

/** Os painéis Wonlex trazem o estado em `enabled` ou em `switchState`, conforme a geração. */
const wonlexEnabled = (desired) => boolValue(desired.enabled ?? desired.switchState, true);

function wonlexBloodPressureWarningInput(desired) {
    return `
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            <div class="row g-3">
                ${field(
                    "Sistólica máxima",
                    numberField("hpWarn", desired.hpWarn ?? 135),
                    { cls: "col-md-6" },
                )}
                ${field(
                    "Diastólica máxima",
                    numberField("LPWarn", desired.LPWarn ?? 90),
                    { cls: "col-md-6" },
                )}
            </div>
        </div>`;
}

function wonlexSleepSettingsInput(desired) {
    return `
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            <div class="row g-3">
                ${field(
                    "Início (HHmmss)",
                    `<input class="form-control" type="text" data-config-field="sleepStartTime" value="${esc(String(desired.sleepStartTime ?? "220000"))}" placeholder="220000">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Fim (HHmmss)",
                    `<input class="form-control" type="text" data-config-field="sleepEndTime" value="${esc(String(desired.sleepEndTime ?? "100000"))}" placeholder="100000">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Meta (minutos)",
                    numberField("sleepTarget", desired.sleepTarget ?? 480),
                    { cls: "col-md-4" },
                )}
            </div>
        </div>`;
}

function wonlexReminderThresholdInput(entry, desired) {
    const valueField = (entry.fields || []).includes("RemindValue")
        ? "RemindValue"
        : "reminderValue";
    const value =
        desired[valueField] ??
        desired.reminderValue ??
        desired.RemindValue ??
        90;
    return `
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            ${field(
                fieldLabel(valueField),
                numberField(valueField, value),
            )}
        </div>`;
}

function wonlexHeartRateRangeInput(desired) {
    const exerciseEnabled = boolValue(
        desired.exerciseEnabled ?? desired.exerciseSwitchState,
        true,
    );
    return `
        <div class="vstack gap-3">
            ${enabledSwitch(wonlexEnabled(desired))}
            <div class="row g-3">
                ${field(
                    "Limite principal",
                    numberField("remindValue", desired.remindValue ?? 120),
                    { cls: "col-md-6" },
                )}
                <div class="col-md-6">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-config-field="exerciseEnabled" ${exerciseEnabled ? "checked" : ""}>
                        <label class="form-check-label">Usar limites de exercício</label>
                    </div>
                </div>
                ${field(
                    "Mínimo exercício",
                    numberField("exerciseHRMin", desired.exerciseHRMin ?? 100),
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Máximo exercício",
                    numberField("exerciseHRMax", desired.exerciseHRMax ?? 140),
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Alerta em exercício",
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

    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">
                Cada plano é enviado separadamente ao relógio. Selecione pelo menos um período e indique a respetiva hora.
            </div>
            <div class="small"><span class="text-danger" aria-hidden="true">*</span> Campo obrigatório</div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="wonlexMedicationPlan">
                    <i class="fa-solid fa-plus me-2"></i>Adicionar medicamento
                </button>
            </div>
            <div class="vstack gap-3" data-repeat-list="wonlexMedicationPlan">
                ${plans.map((plan, index) => wonlexMedicationPlanRow(plan, index)).join("")}
            </div>
        </div>`;
}

export function wonlexMedicationPlanRow(plan = {}, index = 0) {
    const normalized = normalizeWonlexMedicationPlan(plan);
    const rowId = nextUid("wonlex-medication");

    return `
        <div class="border rounded p-3 bg-body" data-repeat-row="wonlexMedicationPlan">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div class="fw-semibold">Medicamento <span data-medication-plan-number>${index + 1}</span></div>
                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow" title="Remover medicamento" aria-label="Remover medicamento">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
            <div class="row g-3">
                ${field(
                    "Tipo",
                    `<select class="form-select" data-medication-field="drugType" required>
                        ${[
                            [0, "Hipertensão"],
                            [1, "Diabetes"],
                            [2, "Colesterol / lípidos"],
                            [3, "Ácido úrico elevado"],
                        ].map(([value, label]) => `
                            <option value="${value}" ${normalized.drugType === value ? "selected" : ""}>${esc(label)}</option>
                        `).join("")}
                    </select>`,
                    { cls: "col-md-4", required: true },
                )}
                ${field(
                    "Nome do medicamento",
                    `<input class="form-control" type="text" data-medication-field="drugName" value="${esc(normalized.drugName)}" placeholder="Ex.: Losartan" required>`,
                    { cls: "col-md-8", required: true },
                )}
                ${field(
                    "Dose",
                    `<input class="form-control" type="number" min="0" step="0.1" data-medication-field="drugDose" value="${esc(String(normalized.drugDose))}">`,
                    { cls: "col-sm-6 col-md-3" },
                )}
                ${field(
                    "Unidade",
                    `<select class="form-select" data-medication-field="drugUnit">
                        ${[
                            ["0", "Comprimido / unidade"],
                            ["1", "Ampola"],
                            ["2", "ml"],
                            ["3", "mg"],
                            ["4", "UI"],
                            ["5", "Outra"],
                        ].map(([value, label]) => `
                            <option value="${value}" ${normalized.drugUnit === value ? "selected" : ""}>${esc(label)}</option>
                        `).join("")}
                    </select>`,
                    { cls: "col-sm-6 col-md-3" },
                )}
                ${field(
                    "Data inicial",
                    `<input class="form-control" type="date" data-medication-field="drugStartTime" value="${esc(normalized.drugStartTime)}" required>`,
                    { cls: "col-sm-6 col-md-3", required: true },
                )}
                ${field(
                    "Data final",
                    `<input class="form-control" type="date" data-medication-field="drugEndTime" value="${esc(normalized.drugEndTime)}" required>`,
                    { cls: "col-sm-6 col-md-3", required: true },
                )}
                ${field(
                    "Intervalo",
                    `<div class="input-group">
                        <input class="form-control" type="number" min="0" step="0.5" data-medication-field="drugInterval" value="${esc(String(normalized.drugInterval))}" required>
                        <span class="input-group-text">dias</span>
                    </div>`,
                    { cls: "col-sm-6 col-md-4", required: true },
                )}
                ${field(
                    "Tomar",
                    `<div class="btn-group" role="group" aria-label="Relação com a refeição">
                        ${[
                            [0, "Antes da refeição"],
                            [1, "Depois da refeição"],
                        ].map(([value, label]) => {
                            const id = `${rowId}-meal-${value}`;
                            return `
                                <input class="btn-check" type="radio" name="${rowId}-meal" id="${id}" value="${value}" data-medication-field="mealTiming" ${normalized.mealTiming === value ? "checked" : ""}>
                                <label class="btn btn-outline-secondary" for="${id}">${esc(label)}</label>
                            `;
                        }).join("")}
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
                        return `
                            <div class="col-sm-6 col-xl-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="${inputId}" value="${period.index}" data-medication-period ${selected ? "checked" : ""}>
                                        <label class="form-check-label" for="${inputId}">${esc(period.label)}</label>
                                    </div>
                                    <input class="form-control form-control-sm" type="time" data-medication-period-time="${period.index}" value="${esc(normalized.alarmClock[period.key] || period.defaultTime)}" ${selected ? "" : "disabled"}>
                                </div>
                            </div>
                        `;
                    }).join("")}
                </div>
            </div>
        </div>`;
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

        return {
            drugType: parseInt(value("drugType"), 10) || 0,
            drugName,
            drugDose: dose,
            drugUnit: value("drugUnit") || "5",
            drugStartTime: start,
            drugEndTime: end,
            drugInterval: interval,
            drugTime: {
                alarmClock,
                checkboxes: selected,
                radio: parseInt(String(
                    row.querySelector("[data-medication-field=\"mealTiming\"]:checked")?.value || "0",
                ), 10) === 1
                    ? 1
                    : 0,
            },
        };
    });

    return { plans };
}

/**
 * Os descritores dos campos da Wonlex.
 *
 * Cada tipo de campo declara aqui as suas quatro faces juntas -- desenhar, ler de volta, o
 * valor inicial e a legenda. Eram quatro mapas separados indexados pela mesma chave, e nada
 * garantia que ficassem alinhados: uma entrada em falta não dava erro, dava um campo genérico.
 */
export const INPUTS = {
    wonlexBloodPressureWarning: {
        render: (_entry, desired) =>
            wonlexBloodPressureWarningInput(desired),
        read: (section) => ({
            // O formulário oferece um limiar sistólico e um diastólico, que é o que a
            // configuração `BPEarlyWarning` da Wonlex leva: ler um valor só perdia os dois.
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
            sleepStartTime: readText(section, "sleepStartTime"),
            sleepEndTime: readText(section, "sleepEndTime"),
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
                [valueField]: readNumber(section, valueField),
            };
        },
        defaults: () => ({ enabled: true, reminderValue: 90 }),
    },
    wonlexHeartRateRange: {
        render: (_entry, desired) => wonlexHeartRateRangeInput(desired),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            remindValue: readNumber(section, "remindValue"),
            exerciseEnabled: readCheckbox(section, "exerciseEnabled"),
            exerciseHRMin: readNumber(section, "exerciseHRMin"),
            exerciseHRMax: readNumber(section, "exerciseHRMax"),
            exerciseRemindValue: readNumber(section, "exerciseRemindValue"),
        }),
        defaults: () => ({
            enabled: true,
            remindValue: 120,
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
        help: () => "Formulário guiado para medicamento, dose, período e horários.",
    },
};
