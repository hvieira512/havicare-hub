import { esc, fieldLabel } from "../../../format.js";
import { field } from "../../../widgets.js";
import {
    WONLEX_MEDICATION_PERIODS,
    boolValue,
    defaultWonlexMedicationPlan,
    normalizeWonlexMedicationPlan,
    normalizeWonlexMedicationPlans,
} from "../normalizers.js";
import { enabledSwitch, nextUid, numberField } from "./shared.js";

/**
 * Os campos que só a Wonlex declara.
 *
 * São doze dos quarenta tipos de campo e valiam cerca de um terço do antigo `inputs.js`. A
 * Wonlex empacota quase tudo em `deviceConfig` e `deviceMeasuringFrequency`, e por isso cada
 * limiar precisa do seu próprio formulário em vez de um campo numérico solto.
 */

/** Os painéis Wonlex trazem o estado em `enabled` ou em `switchState`, conforme a geração. */
const wonlexEnabled = (desired) => boolValue(desired.enabled ?? desired.switchState, true);

export function wonlexBloodPressureWarningInput(desired) {
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

export function wonlexSleepSettingsInput(desired) {
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

export function wonlexReminderThresholdInput(entry, desired) {
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

export function wonlexHeartRateRangeInput(desired) {
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

export function wonlexMedicationPlansInput(desired) {
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
