import { field } from "../../../components/form-field.js";
import { medicationPlanTimes } from "../../medication-plan.js";
import { hhmm } from "../../../format.js";
import { html } from "../../../html.js";
import { choiceField, enabledSwitch, entryFieldLabel, entryText, fieldChoices, readChoice } from "./shared.js";
import { readCheckbox, readText } from "../readers.js";

// Os alarmes são fixos, tantos quantos o `limit` da definição; o número liga cada um ao reportado.

/** O «sem alarme» do M228: um slot a `00:00` toca e gasta um compartimento todos os dias. */
const UNSET_HOUR = 24;
const UNSET_MINUTE = 60;

const toTimeValue = (hour, minute) =>
    hour === undefined || hour === null || hour >= UNSET_HOUR || minute >= UNSET_MINUTE
        ? ""
        : hhmm(hour, minute);

const fromTimeValue = (value) => {
    const [hour, minute] = String(value ?? "").split(":");
    return { hour: Number(hour) || 0, minute: Number(minute) || 0 };
};

const isBlank = (value) => String(value ?? "").trim() === "";

const timeField = (configField, hour, minute) =>
    html`<input class="form-control" type="time" data-config-field="${configField}"
        value="${(toTimeValue(hour, minute))}">`;

/** O nome fica no hub e não vai ao aparelho; o limite é o que a API aceita. */
const nameField = (index, name, label) =>
    html`<input class="form-control" type="text" maxlength="64" data-config-field="name-${String(index)}"
        placeholder="${label}" aria-label="${label}" value="${name ?? ""}">`;

/** Sem interruptor: o `0x1041`--`0x1049` é inerte, e há alarme quando há hora. */
const slotCell = (index, plan, nameLabel) =>
    html`
        <div class="col">
            <div class="border rounded-3 px-2 py-1" data-alarm-slot="${String(index)}">
                <div class="d-flex align-items-center gap-2">
                    <div class="text-secondary small flex-shrink-0">${String(index + 1)}</div>
                    ${timeField(`time-${index}`, plan?.hour, plan?.minute)}
                </div>
                <div class="mt-1">${nameField(index, plan?.name, nameLabel)}</div>
            </div>
        </div>`;

function alarmsInput(entry, desired, meta) {
    const slots = Number(entry.limit) || 0;
    // Pelo número do alarme e não pela posição: um plano só do alarme 5 cairia na primeira caixa.
    const bySlot = new Map(
        medicationPlanTimes(desired?.plans).map((entry) => [entry.slot, entry]),
    );
    const nameLabel = entryFieldLabel(entry, meta, "name");
    const cells = Array.from({ length: slots }, (_, index) =>
        slotCell(index, bySlot.get(index + 1), nameLabel),
    );

    return html`<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-2">${cells}</div>`;
}

/** Deixa cair os slots em branco; cada plano leva o número do seu alarme e o nome, quando há. */
function readAlarms(section) {
    const plans = [];
    for (let index = 0; section.querySelector(`[data-config-field="time-${index}"]`); index++) {
        const value = readText(section, `time-${index}`);
        if (isBlank(value)) continue;
        const { hour, minute } = fromTimeValue(value);
        const name = readText(section, `name-${index}`);
        plans.push({
            ...(name === "" ? {} : { name }),
            times: [{
                time: hhmm(hour, minute),
                enabled: true,
                slot: index + 1,
                recurrence: { kind: "daily" },
            }],
        });
    }

    return { plans };
}

const dateField = (name, value) =>
    html`<input class="form-control" type="date" data-config-field="${name}" value="${(String(value ?? ""))}">`;

/** Do mais alto (`0`) ao silêncio; os valores vêm da definição, os ícones e tons daqui. */
const VOLUME_STEPS = {
    0: { icon: "fa-volume-high", tone: "primary" },
    1: { icon: "fa-volume-low", tone: "secondary" },
    2: { icon: "fa-volume-off", tone: "warning" },
    3: { icon: "fa-volume-xmark", tone: "danger" },
};

function volumeScale(entry, desired, meta) {
    const name = entry.fields?.[0] || "volume";

    return choiceField({
        field: name,
        value: desired?.[name],
        label: entryText(entry, meta).label,
        options: fieldChoices(entry, meta, name).map((option) => ({ ...option, ...(VOLUME_STEPS[option.value] || {}) })),
    });
}

export const INPUTS = {
    volumeScale: {
        render: volumeScale,
        read: (section) => {
            const name = section.querySelector("input[type=radio][data-config-field]")?.dataset.configField ?? "volume";
            return { [name]: readChoice(section, name) };
        },
    },
    pillDispenserPeriod: {
        render: (entry, desired) =>
            enabledSwitch(Boolean(desired?.enabled)) +
            html`<div class="row row-cols-1 row-cols-sm-auto g-2 mt-1">
                <div class="col">${field("Início", dateField("startDate", desired?.startDate))}</div>
                <div class="col">${field("Fim", dateField("endDate", desired?.endDate))}</div>
            </div>`,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            startDate: readText(section, "startDate"),
            endDate: readText(section, "endDate"),
        }),
    },
    pillDispenserAlarms: {
        render: alarmsInput,
        read: readAlarms,
    },
    pillDispenserQuietHours: {
        render: (entry, desired) =>
            enabledSwitch(Boolean(desired?.enabled)) +
            html`<div class="row row-cols-1 row-cols-sm-auto g-2 mt-1">
                <div class="col">${field("Início", timeField("start", desired?.startHour, desired?.startMinute))}</div>
                <div class="col">${field("Fim", timeField("end", desired?.endHour, desired?.endMinute))}</div>
            </div>`,
        read: (section) => {
            const start = fromTimeValue(readText(section, "start"));
            const end = fromTimeValue(readText(section, "end"));

            return {
                enabled: readCheckbox(section, "enabled"),
                startHour: start.hour,
                startMinute: start.minute,
                endHour: end.hour,
                endMinute: end.minute,
            };
        },
    },
};
