import { displayPersonIndex, fieldValue } from "../../format.js";
import { postureStyle } from "../../domain.js";
import { html } from "../../html.js";
import { compactDetails } from "./shared.js";

/**
 * Os cartões do radar: presença, posturas e as estatísticas por minuto.
 *
 * Não se cruzam com nenhum outro aparelho -- uma postura ou uma contagem de pessoas não
 * existe num relógio --, e é essa a linha que os separa daqui. A do tipo de dispositivo não
 * serve: a frequência cardíaca sai de relógio, radar e pulseira.
 */

/**
 * Quantas pessoas o radar vê. O `count` vem do hub e não se conta o array aqui: um radar
 * que não vê ninguém está a funcionar, e diz "Ninguém" e não "Sem leituras".
 */
export function presenceValue(data) {
    const count = Number(data?.count ?? 0) || 0;
    if (count === 0) {
        return "Ninguém";
    }

    return `${count} pessoa${count === 1 ? "" : "s"}`;
}

/** A pastilha é um `badge` do Bootstrap com o par de utilitários subtis do tom. */
const CHIP_CLASS =
    "badge rounded-pill fw-normal d-inline-flex align-items-center gap-1";

/**
 * Uma postura como pastilha. A enumeração vem do payload e vai parar a um `class`, por isso
 * sai escapada: um estado novo do firmware não pode escrever atributos.
 */
function postureChip(posture) {
    const style = postureStyle(posture);
    const tone = style.tone;
    const label = fieldValue("posture", posture);

    return html`<span class="${CHIP_CLASS} bg-${tone}-subtle text-${tone}-emphasis" title="${label}"><i class="fa-solid ${style.icon}" aria-hidden="true"></i>${label}</span>`;
}

/** Quantas pastilhas cabem antes de o mosaico crescer de mais. */
const PRESENCE_CHIP_LIMIT = 3;

/**
 * A postura de cada pessoa, em pastilhas. As coordenadas ficam na tooltip: num mosaico
 * estreito enchiam a linha, e não significam nada sem uma planta da divisão.
 */
export function presenceDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    const chips = people
        .slice(0, PRESENCE_CHIP_LIMIT)
        .map((person) => postureChip(person?.posture));
    const hidden = people.length - chips.length;

    if (hidden > 0) {
        chips.push(
            html`<span class="${CHIP_CLASS} bg-secondary-subtle text-secondary-emphasis">+${hidden}</span>`,
        );
    }

    return chips.join("");
}

/**
 * As pessoas todas, com onde estão, sem corte: é aqui que as coordenadas e a quarta pessoa
 * em diante existem, para quem esteja a comparar com a especificação do fabricante.
 */
export function presenceDetailsTitle(data) {
    const people = Array.isArray(data?.people) ? data.people : [];

    return people
        .map((person, index) => {
            const personIndex = displayPersonIndex(
                person?.personIndex ?? index,
            );
            const posture = fieldValue("posture", person?.posture);
            const x = dataPointValue(person?.xPositionDm);
            const y = dataPointValue(person?.yPositionDm);
            const z = dataPointValue(person?.zPositionCm);
            return `Pessoa ${personIndex}: ${posture} · x ${x} dm · y ${y} dm · z ${z} cm`;
        })
        .join(" · ");
}

export function radarPositionMinuteStatsValue(data) {
    const people = dataPointValue(data?.people);
    const distance = dataPointValue(data?.walkingDistance);
    if (people === "-" && distance === "-") {
        return "Sem leituras";
    }

    return `${people !== "-" ? `${people} pessoas` : "-"} · ${distance !== "-" ? `${distance} m` : "-"}`;
}

export function radarPositionMinuteStatsDetails(data) {
    return compactDetails(data, [
        "walkingTimeS",
        "meditationTimeS",
        "inBedTimeS",
        "standingTimeS",
        "multiplayerTimeS",
        "breathingActive",
    ]);
}

export function radarVitalsMinuteStatsValue(data) {
    const heartRate = dataPointValue(data?.avgHeartRate);
    const breathing = dataPointValue(data?.avgBreathing);
    if (heartRate === "-" && breathing === "-") {
        return "Sem leituras";
    }

    return `${heartRate !== "-" ? `${heartRate} bpm` : "-"} · ${breathing !== "-" ? `${breathing} rpm` : "-"}`;
}

export function radarVitalsMinuteStatsDetails(data) {
    return compactDetails(data, [
        "breathingStatus",
        "heartRateStatus",
        "vitalSignsStatus",
    ]);
}

function dataPointValue(value) {
    return value === undefined || value === null || value === ""
        ? "-"
        : String(value);
}
