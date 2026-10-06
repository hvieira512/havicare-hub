import { field } from "../../../components/form-field.js";
import { medicationPlanTimes } from "../../medication-plan.js";
import { html, raw } from "../../../html.js";
import { segmentedScale } from "./segmented-scale.js";
import { enabledSwitch, nextUid } from "./shared.js";
import { selectOptions } from "./generic.js";
import { readCheckbox, readText } from "../readers.js";

/**
 * O dispensador tem **nove alarmes fixos**, que não se criam nem apagam: o formulário mostra
 * os nove, e o número do slot liga cada um ao estado que o aparelho reporta.
 */

const SLOTS = 9;

/**
 * O «sem alarme» do M228, que ele devolve e aceita. A meia-noite não serve de vazio: um slot a
 * `00:00` toca e gasta um compartimento todos os dias.
 */
const UNSET_HOUR = 24;
const UNSET_MINUTE = 60;

const pad = (value) => String(value).padStart(2, "0");

/** `HH:MM` para o seletor, ou vazio quando não há hora nenhuma para mostrar. */
const toTimeValue = (hour, minute) =>
    hour === undefined || hour === null || hour >= UNSET_HOUR || minute >= UNSET_MINUTE
        ? ""
        : `${pad(hour)}:${pad(minute)}`;

/** De `HH:MM` de volta a hora e minuto, que é o que as TAGs do aparelho levam. */
const fromTimeValue = (value) => {
    const [hour, minute] = String(value ?? "").split(":");
    return { hour: Number(hour) || 0, minute: Number(minute) || 0 };
};

const isBlank = (value) => String(value ?? "").trim() === "";

const timeField = (configField, hour, minute) =>
    html`<input class="form-control" type="time" data-config-field="${configField}"
        value="${(toTimeValue(hour, minute))}">`;

/**
 * Sem interruptor: nesta firmware o `0x1041`--`0x1049` é inerte, e quem decide se há alarme é
 * a hora estar preenchida.
 */
const slotCell = (index, plan) =>
    html`
        <div class="col">
            <div class="d-flex align-items-center gap-2 border rounded-3 px-2 py-1" data-alarm-slot="${String(index)}">
                <div class="text-secondary small flex-shrink-0">${String(index + 1)}</div>
                ${raw(timeField(`time-${index}`, plan?.hour, plan?.minute))}
            </div>
        </div>`;

/** Sem rótulo próprio: o cartão da configuração já mostra «Plano de medicação» por cima. */
function alarmsInput(entry, desired) {
    // Pelo número do alarme, não pela posição na lista: pela posição, um plano só do alarme 5
    // cai na primeira caixa e mostra um número que não é o dele.
    const bySlot = new Map(
        medicationPlanTimes(desired?.plans).map((entry) => [entry.slot, entry]),
    );
    const cells = Array.from({ length: SLOTS }, (_, index) =>
        slotCell(index, bySlot.get(index + 1)),
    );

    return html`<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-2">${raw(cells.join(""))}</div>`;
}

/**
 * Lê os nove slots e deixa cair os em branco. Cada plano leva o número do seu alarme, para a
 * lista compactada não escrever no slot errado.
 */
function readAlarms(section) {
    const plans = [];
    for (let index = 0; index < SLOTS; index++) {
        const value = readText(section, `time-${index}`);
        if (isBlank(value)) continue;
        const { hour, minute } = fromTimeValue(value);
        plans.push({
            times: [{
                time: `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}`,
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

/**
 * A escala do volume, do mais alto (`0`) ao silêncio. Valores e rótulos vêm da definição; os
 * ícones e os tons ficam aqui porque são apresentação deste aparelho.
 */
const VOLUME_STEPS = {
    0: { icon: "fa-volume-high", tone: "primary" },
    1: { icon: "fa-volume-low", tone: "secondary" },
    2: { icon: "fa-volume-off", tone: "warning" },
    3: { icon: "fa-volume-xmark", tone: "danger" },
};

function volumeScale(entry, desired) {
    const { name, options, fallback } = selectOptions(entry);

    return segmentedScale({
        name: nextUid(`cfg-${name}`),
        field: name,
        value: desired?.[name] ?? fallback,
        label: entry.label || "Volume",
        // Por valor e não por posição, para reordenar a definição não trocar os ícones.
        options: options.map((option) => ({ ...option, ...(VOLUME_STEPS[option.value] || {}) })),
    });
}

export const INPUTS = {
    volumeScale: {
        // `render` e não `control`: a escala ocupa a largura do cartão, por baixo do título.
        render: volumeScale,
        read: (section) => {
            const node = section.querySelector("input[type=radio][data-config-field]:checked");
            if (!node) return {};
            const value = String(node.value ?? "");
            return {
                [node.dataset.configField]:
                    value !== "" && !Number.isNaN(Number(value)) ? Number(value) : value,
            };
        },
        defaults: (entry) => {
            const { name, options } = selectOptions(entry);
            return { [name]: entry.options?.default ?? options[0]?.value ?? 0 };
        },
    },
    pillDispenserPeriod: {
        render: (entry, desired) =>
            enabledSwitch(Boolean(desired?.enabled)) +
            html`<div class="row row-cols-1 row-cols-sm-auto g-2 mt-1">
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
            html`<div class="row row-cols-1 row-cols-sm-auto g-2 mt-1">
                <div class="col">${raw(field("Início", timeField("start", desired?.startHour, desired?.startMinute)))}</div>
                <div class="col">${raw(field("Fim", timeField("end", desired?.endHour, desired?.endMinute)))}</div>
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
        defaults: () => ({
            enabled: false,
            startHour: 22,
            startMinute: 0,
            endHour: 7,
            endMinute: 0,
        }),
    },
};
