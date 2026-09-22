import { esc } from "../../../format.js";
import { field } from "../../../components/form-field.js";
import { html, raw } from "../../../html.js";
import { segmentedScale } from "../../../components/segmented-scale.js";
import { enabledSwitch, nextUid } from "./shared.js";
import { selectOptions } from "./generic.js";
import { readCheckbox, readText } from "../readers.js";

/**
 * Os campos do dispensador de comprimidos.
 *
 * O aparelho tem **nove alarmes fixos**: não se criam nem se apagam, ligam-se e desligam-se.
 * Por isso o formulário mostra sempre os nove, e não uma lista a que se acrescentam linhas —
 * é essa a diferença entre este campo e um plano de lembretes de relógio.
 */

/** O aparelho tem nove, e o formulário mostra os nove. */
const SLOTS = 9;

/**
 * O M228 devolve `24` e `60` nos alarmes que nunca foram definidos. Não são uma hora: são o
 * sentinela dele, e desenhá-los à letra punha «24:60» no ecrã.
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

const timeField = (configField, hour, minute) =>
    html`<input class="form-control" type="time" data-config-field="${esc(configField)}"
        value="${esc(toTimeValue(hour, minute))}">`;

const slotCell = (index, plan) => {
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
                ${raw(timeField(`time-${index}`, plan?.hour, plan?.minute))}
            </div>
        </div>`;
};

/**
 * Sem rótulo próprio: o cartão da configuração já mostra "Plano de medicação" por cima, e
 * repeti-lo dava o mesmo texto duas vezes seguidas.
 */
function alarmsInput(entry, desired) {
    const plans = Array.isArray(desired?.plans) ? desired.plans : [];
    // Pelo número do alarme, não pela posição na lista: um plano só do alarme 5 aparecia na
    // primeira caixa, e o utilizador via um número que não era o dele.
    const bySlot = new Map(
        plans.map((plan, position) => [Number(plan?.slot ?? position + 1), plan]),
    );
    const cells = Array.from({ length: SLOTS }, (_, index) =>
        slotCell(index, bySlot.get(index + 1)),
    );

    return html`<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-2">${raw(cells.join(""))}</div>`;
}

/**
 * Lê os nove slots de volta, e deixa cair os desligados que não têm hora: o que vai para o
 * aparelho é a lista dos que ficam, e o resto é desligado por omissão.
 *
 * Cada plano leva o número do alarme em que fica. Sem ele a lista compactava-se e o enésimo
 * plano caía no enésimo alarme: escolher o 5 escrevia no 3, por cima do que lá estivesse.
 */
function readAlarms(section) {
    const plans = [];
    for (let index = 0; index < SLOTS; index++) {
        const enabled = readCheckbox(section, `enabled-${index}`);
        const { hour, minute } = fromTimeValue(readText(section, `time-${index}`));
        if (!enabled && hour === 0 && minute === 0) continue;
        plans.push({ slot: index + 1, hour, minute, enabled });
    }

    return { plans };
}

const dateField = (name, value) =>
    html`<input class="form-control" type="date" data-config-field="${esc(name)}" value="${esc(String(value ?? ""))}">`;

/**
 * A escala do volume, do mais alto ao silêncio.
 *
 * Os valores e os rótulos vêm da definição, como em qualquer escolha; os ícones e os tons
 * ficam aqui porque são deste aparelho. Uma escala genérica não tem como saber que o `0` do
 * M228 é um altifalante cheio, e declarar nomes de ícones nas definições em PHP era pôr
 * apresentação no sítio errado.
 */
const VOLUME_STEPS = [
    { icon: "fa-volume-high", tone: "primary" },
    { icon: "fa-volume-low", tone: "secondary" },
    { icon: "fa-volume-off", tone: "warning" },
    { icon: "fa-volume-xmark", tone: "danger" },
];

function volumeScale(entry, desired) {
    const { name, options, fallback } = selectOptions(entry);

    return segmentedScale({
        name: nextUid(`cfg-${name}`),
        field: name,
        value: desired?.[name] ?? fallback,
        label: entry.label || "Volume",
        options: options.map((option, index) => ({ ...option, ...(VOLUME_STEPS[index] || {}) })),
    });
}

export const INPUTS = {
    volumeScale: {
        // `render` e não `control`: a escala ocupa a largura do cartão, por baixo do título,
        // como a sensibilidade de queda. Espremida na linha do título, as quatro posições
        // voltavam a ficar encostadas a um canto, que é o que isto existe para resolver.
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
            html`<div class="row row-cols-1 row-cols-sm-2 g-2 mt-1">
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
