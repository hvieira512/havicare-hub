import { esc, fieldLabel } from "../../../format.js";
import { field } from "../../../widgets.js";
import { html, raw } from "../../../html.js";
import { enabledSwitch, numberField } from "./shared.js";
import { readCheckbox, readNumber } from "../readers.js";

/**
 * Os campos do dispensador de comprimidos.
 *
 * O aparelho tem **nove alarmes fixos**: não se criam nem se apagam, ligam-se e desligam-se.
 * Por isso o formulário mostra sempre os nove, e não uma lista a que se acrescentam linhas —
 * é essa a diferença entre este campo e um plano de lembretes de relógio.
 */

/** O aparelho tem nove, e o formulário mostra os nove. */
const SLOTS = 9;

const slotRow = (index, plan) => {
    const hour = plan?.hour ?? 0;
    const minute = plan?.minute ?? 0;
    const enabled = plan ? plan.enabled !== false : false;

    return html`
        <div class="d-flex align-items-end gap-2 mb-2" data-alarm-slot="${String(index)}">
            <div class="text-secondary small" style="min-width:4.5rem">Alarme ${String(index + 1)}</div>
            <div style="max-width:6rem">
                ${raw(numberField(`hour-${index}`, hour, { min: 0, max: 23 }))}
            </div>
            <div class="text-secondary">:</div>
            <div style="max-width:6rem">
                ${raw(numberField(`minute-${index}`, minute, { min: 0, max: 59 }))}
            </div>
            <div class="form-check form-switch ms-2">
                <input class="form-check-input" type="checkbox" role="switch"
                    data-config-field="enabled-${String(index)}" ${raw(enabled ? "checked" : "")}>
            </div>
        </div>`;
};

function alarmsInput(entry, desired) {
    const plans = Array.isArray(desired?.plans) ? desired.plans : [];
    const rows = Array.from({ length: SLOTS }, (_, index) => slotRow(index, plans[index]));

    return field(
        "Plano de medicação",
        rows.join(""),
        {
            help: "Os nove alarmes do aparelho. O plano é enviado inteiro — um alarme" +
                " desligado aqui fica desligado lá.",
        },
    );
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

export const INPUTS = {
    pillDispenserAlarms: {
        render: alarmsInput,
        read: readAlarms,
        defaults: () => ({ plans: [] }),
    },
    pillDispenserSound: {
        // Cinco níveis em cada, como o aparelho os numera.
        render: numbers([
            { name: "volume", min: 0, max: 4, label: "Volume" },
            { name: "ringtone", min: 0, max: 4, label: "Tipo de toque" },
        ]),
        read: readNumbers(["volume", "ringtone"]),
        defaults: () => ({ volume: 2, ringtone: 0 }),
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
    pillDispenserRegion: {
        render: numbers([
            { name: "language", min: 0, max: 20, label: "Idioma" },
            // Com sinal: os fusos a oeste de Greenwich são negativos.
            { name: "timezoneMinutes", min: -720, max: 840, label: "Fuso horário (minutos)" },
        ]),
        read: readNumbers(["language", "timezoneMinutes"]),
        defaults: () => ({ language: 0, timezoneMinutes: 0 }),
    },
    pillDispenserDispenseMode: {
        render: (entry, desired) =>
            [
                ["earlyRetrieval", "Toma antecipada", "Deixa levantar antes da hora."],
                ["childLock", "Bloqueio de criança", "Protege o prato."],
            ]
                .map(([name, label, help]) =>
                    field(
                        label,
                        html`<div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch"
                                data-config-field="${esc(name)}" ${raw(desired?.[name] ? "checked" : "")}>
                        </div>`,
                        { help },
                    ),
                )
                .join(""),
        read: (section) => ({
            earlyRetrieval: readCheckbox(section, "earlyRetrieval"),
            childLock: readCheckbox(section, "childLock"),
        }),
        defaults: () => ({ earlyRetrieval: false, childLock: false }),
    },
};
