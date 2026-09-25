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
 * O aparelho tem **nove alarmes fixos**: não se criam nem se apagam. Por isso o formulário
 * mostra sempre os nove, e não uma lista a que se acrescentam linhas — o número do slot é o
 * que liga cada alarme ao estado que o aparelho reporta dele.
 */

/** O aparelho tem nove, e o formulário mostra os nove. */
const SLOTS = 9;

/**
 * O «sem alarme» do M228, que ele devolve nos que nunca foram definidos e aceita de volta.
 *
 * Não é uma hora: desenhá-lo à letra punha «24:60» no ecrã. A meia-noite não serve de vazio,
 * porque um slot a `00:00` toca e gasta um compartimento todos os dias.
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

/** Um campo de hora em branco. */
const isBlank = (value) => String(value ?? "").trim() === "";

const timeField = (configField, hour, minute) =>
    html`<input class="form-control" type="time" data-config-field="${esc(configField)}"
        value="${esc(toTimeValue(hour, minute))}">`;

/**
 * Sem interruptor: o `0x1041`--`0x1049` desta firmware é inerte. O aparelho aceita-o,
 * guarda-o, devolve-o numa leitura e toca na mesma, e o ecrã dele desenha os dois casos
 * iguais. Quem decide se há alarme é a hora estar preenchida.
 */
const slotCell = (index, plan) =>
    html`
        <div class="col">
            <div class="d-flex align-items-center gap-2 border rounded-3 px-2 py-1" data-alarm-slot="${String(index)}">
                <div class="text-secondary small flex-shrink-0">${String(index + 1)}</div>
                ${raw(timeField(`time-${index}`, plan?.hour, plan?.minute))}
            </div>
        </div>`;

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
 * Lê os nove slots de volta, e deixa cair os que ficaram em branco: o que vai para o aparelho
 * é a lista dos que ficam, e o resto sai vazio por omissão.
 *
 * Cada plano leva o número do alarme em que fica. Sem ele a lista compactava-se e o enésimo
 * plano caía no enésimo alarme: escolher o 5 escrevia no 3, por cima do que lá estivesse.
 */
function readAlarms(section) {
    const plans = [];
    for (let index = 0; index < SLOTS; index++) {
        const value = readText(section, `time-${index}`);
        if (isBlank(value)) continue;
        plans.push({ slot: index + 1, ...fromTimeValue(value) });
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
        // Por valor e não por posição: reordenar a lista na definição trocava os ícones, e o
        // silêncio ficava com um altifalante cheio sem nada a denunciá-lo.
        options: options.map((option) => ({ ...option, ...(VOLUME_STEPS[option.value] || {}) })),
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
