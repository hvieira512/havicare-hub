import { displayPersonIndex, fieldValue } from "../../format.js";
import { postureStyle } from "../../radar-style.js";
import { html } from "../../html.js";
import { compactDetails, joinMarkup } from "./shared.js";

/** Quantas pessoas o radar vê, pelo `peopleCount` do hub: sem ninguém, diz «Ninguém» e não «Sem leituras». */
export function presenceValue(data) {
    const count = Number(data?.peopleCount ?? 0) || 0;
    if (count === 0) {
        return "Ninguém";
    }

    return `${count} pessoa${count === 1 ? "" : "s"}`;
}

const CHIP_CLASS =
    "badge rounded-pill fw-normal d-inline-flex align-items-center gap-1";

/** Uma postura como pastilha, escapada: um estado novo do firmware não pode escrever atributos. */
function postureChip(posture) {
    const style = postureStyle(posture);
    const tone = style.tone;
    const label = fieldValue("posture", posture);

    return html`<span class="${CHIP_CLASS} bg-${tone}-subtle text-${tone}-emphasis" title="${label}"><i class="fa-solid ${style.icon}" aria-hidden="true"></i>${label}</span>`;
}

const PRESENCE_CHIP_LIMIT = 3;

/** A postura de cada pessoa, em pastilhas; as coordenadas ficam na tooltip. */
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

    return joinMarkup(chips, "");
}

/** As pessoas todas, com onde estão: as coordenadas e a quarta pessoa em diante só existem aqui. */
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
    const people = dataPointValue(data?.peopleCount);
    const distance = dataPointValue(data?.walkingDistanceMeters);
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
    const heartRate = dataPointValue(data?.avgHeartRateBpm);
    const breathing = dataPointValue(data?.avgBreathsPerMinute);
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
