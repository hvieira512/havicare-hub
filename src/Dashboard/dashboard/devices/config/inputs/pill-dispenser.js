import { esc, fieldLabel } from "../../../format.js";
import { field } from "../../../widgets.js";
import { html, raw } from "../../../html.js";
import { enabledSwitch, numberField } from "./shared.js";
import { readCheckbox, readNumber, readText } from "../readers.js";

/**
 * Os campos do dispensador de comprimidos.
 *
 * O aparelho tem **nove alarmes fixos**: não se criam nem se apagam, ligam-se e desligam-se.
 * Por isso o formulário mostra sempre os nove, e não uma lista a que se acrescentam linhas —
 * é essa a diferença entre este campo e um plano de lembretes de relógio.
 */

/** O aparelho tem nove, e o formulário mostra os nove. */
const SLOTS = 9;

const slotCell = (index, plan) => {
    const hour = plan?.hour ?? 0;
    const minute = plan?.minute ?? 0;
    const enabled = plan ? plan.enabled !== false : false;

    return html`
        <div class="col">
            <div class="d-flex align-items-center gap-2 border rounded-3 px-2 py-1" data-alarm-slot="${String(index)}">
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                        aria-label="Alarme ${String(index + 1)}"
                        data-config-field="enabled-${String(index)}" ${raw(enabled ? "checked" : "")}>
                </div>
                <div class="text-secondary small flex-shrink-0">${String(index + 1)}</div>
                ${raw(numberField(`hour-${index}`, hour, { min: 0, max: 23 }))}
                <div class="text-secondary">:</div>
                ${raw(numberField(`minute-${index}`, minute, { min: 0, max: 59 }))}
            </div>
        </div>`;
};

/**
 * Sem rótulo próprio: o cartão da configuração já mostra "Plano de medicação" por cima, e
 * repeti-lo dava o mesmo texto duas vezes seguidas.
 */
function alarmsInput(entry, desired) {
    const plans = Array.isArray(desired?.plans) ? desired.plans : [];
    const cells = Array.from({ length: SLOTS }, (_, index) => slotCell(index, plans[index]));

    return html`<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-2">${raw(cells.join(""))}</div>`;
}

/**
 * Lê os nove slots de volta, e deixa cair os desligados que não têm hora: o que vai para o
 * aparelho é a lista dos que ficam, e o resto é desligado por omissão.
 */
function readAlarms(section) {
    const plans = [];
    for (let index = 0; index < SLOTS; index++) {
        const enabled = readCheckbox(section, `enabled-${index}`);
        const hour = readNumber(section, `hour-${index}`) || 0;
        const minute = readNumber(section, `minute-${index}`) || 0;
        if (!enabled && hour === 0 && minute === 0) continue;
        plans.push({ hour, minute, enabled });
    }

    return { plans };
}

const numbers = (specs) => (entry, desired) =>
    specs
        .map(({ name, min, max, label }) =>
            field(
                label || fieldLabel(name),
                numberField(name, desired?.[name] ?? min, { min, max }),
            ),
        )
        .join("");

const readNumbers = (names) => (section) =>
    Object.fromEntries(names.map((name) => [name, readNumber(section, name)]));

const dateField = (name, value) =>
    html`<input class="form-control" type="date" data-config-field="${esc(name)}" value="${esc(String(value ?? ""))}">`;

export const INPUTS = {
    pillDispenserPeriod: {
        render: (entry, desired) =>
            enabledSwitch(Boolean(desired?.enabled)) +
            html`<div class="row row-cols-1 row-cols-sm-2 g-2 mt-1">
                <div class="col">${raw(field("Início", dateField("startDate", desired?.startDate)))}</div>
                <div class="col">${raw(field("Fim", dateField("endDate", desired?.endDate)))}</div>
            </div>`,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            startDate: readText(section, "startDate"),
            endDate: readText(section, "endDate"),
        }),
        defaults: () => ({ enabled: false, startDate: "", endDate: "" }),
    },
    pillDispenserAlarms: {
        render: alarmsInput,
        read: readAlarms,
        defaults: () => ({ plans: [] }),
    },
    pillDispenserQuietHours: {
        render: (entry, desired) =>
            enabledSwitch(Boolean(desired?.enabled)) +
            numbers([
                { name: "startHour", min: 0, max: 23, label: "Hora de início" },
                { name: "startMinute", min: 0, max: 59, label: "Minuto de início" },
                { name: "endHour", min: 0, max: 23, label: "Hora de fim" },
                { name: "endMinute", min: 0, max: 59, label: "Minuto de fim" },
            ])(entry, desired),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            ...readNumbers(["startHour", "startMinute", "endHour", "endMinute"])(section),
        }),
        defaults: () => ({
            enabled: false,
            startHour: 22,
            startMinute: 0,
            endHour: 7,
            endMinute: 0,
        }),
    },
};
