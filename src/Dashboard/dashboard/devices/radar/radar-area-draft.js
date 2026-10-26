import { areaName, areaTypeStyle } from "../../core/radar-style.js";

/**
 * O rascunho das áreas de um radar, em decímetros e no formato do contrato (`xMinDm`…). O `key`
 * é o `areaId` da telemetria: uma área mantém-no enquanto existir.
 */

/** Os tipos que se criam, pela ordem do menu, com o tamanho com que nascem (largura × comprimento). */
export const CREATABLE_TYPES = [
    { type: "monitoring_bed", size: [10, 19] },
    { type: "bed", size: [9, 19] },
    { type: "door", size: [8, 3] },
    { type: "alarm_area", size: [8, 8] },
    { type: "interference", size: [6, 6] },
    { type: "custom", size: [5, 5] },
];

/** O `maxAreas` vem da planta da API. */
export function firstFreeKey(areas, maxAreas) {
    const used = new Set(areas.map((area) => area.key));
    for (let key = 0; key < maxAreas; key += 1) {
        if (!used.has(key)) return key;
    }
    return null;
}

/** Acrescenta uma área do tipo pedido centrada em `[x, y]`; sem `key` livre, fica como estava. */
export function addArea(areas, type, [x, y], maxAreas) {
    const key = firstFreeKey(areas, maxAreas);
    if (key === null) return areas;

    const [width, length] = CREATABLE_TYPES.find((entry) => entry.type === type)?.size ?? [5, 5];
    const xMinDm = Math.round(x - width / 2);
    const yMinDm = Math.round(y - length / 2);
    return [...areas, {
        key,
        type,
        name: areaTypeStyle(type).label,
        xMinDm,
        yMinDm,
        xMaxDm: xMinDm + width,
        yMaxDm: yMinDm + length,
    }];
}

const FIELDS = ["type", "name", "xMinDm", "yMinDm", "xMaxDm", "yMaxDm"];

export function originalOf(area, originals) {
    return originals.find((original) => original.key === area.key) ?? null;
}

/** @returns {"applied"|"edited"|"new"|"deleted"} */
export function areaStatus(area, originals) {
    if (area.deleted) return "deleted";
    const original = originalOf(area, originals);
    if (!original) return "new";
    return FIELDS.some((field) => original[field] !== area[field]) ? "edited" : "applied";
}

/** Uma área do radar fica marcada e sai no envio; uma só do rascunho sai já. */
export function toggleDelete(areas, key, originals) {
    const area = areas.find((candidate) => candidate.key === key);
    if (!area) return areas;
    if (!originalOf(area, originals)) return areas.filter((candidate) => candidate.key !== key);
    return areas.map((candidate) => candidate.key === key ? { ...candidate, deleted: !candidate.deleted } : candidate);
}

/** O que se envia: todas as áreas que ficam. O backend apaga as que faltam. */
export function draftItems(areas) {
    return areas
        .filter((area) => !area.deleted)
        .map(({ key, type, name, xMinDm, yMinDm, xMaxDm, yMaxDm }) => ({ key, type, name, xMinDm, yMinDm, xMaxDm, yMaxDm }));
}

export function moveArea(area, dx, dy) {
    const x = Math.round(dx);
    const y = Math.round(dy);
    return { ...area, xMinDm: area.xMinDm + x, xMaxDm: area.xMaxDm + x, yMinDm: area.yMinDm + y, yMaxDm: area.yMaxDm + y };
}

/**
 * Puxa um canto: `corner` é `"xy"`, com 1 para o lado máximo e 0 para o mínimo. O lado oposto
 * fica parado, e a área nunca fica abaixo de 10 cm.
 */
export function resizeArea(area, corner, dx, dy) {
    const [right, top] = corner.split("").map((digit) => digit === "1");
    const next = { ...area };
    if (right) next.xMaxDm = Math.max(area.xMinDm + 1, area.xMaxDm + Math.round(dx));
    else next.xMinDm = Math.min(area.xMaxDm - 1, area.xMinDm + Math.round(dx));
    if (top) next.yMaxDm = Math.max(area.yMinDm + 1, area.yMaxDm + Math.round(dy));
    else next.yMinDm = Math.min(area.yMaxDm - 1, area.yMinDm + Math.round(dy));
    return next;
}

const RULE_TEXT = {
    door_off_wall: (area) => `A porta «${areaName(area)}» tem de ficar encostada a uma parede.`,
    outside_room: (area) => `«${areaName(area)}» sai dos limites de deteção; o radar não vê essa parte.`,
    second_monitoring_bed: () => "Só pode haver uma cama de monitorização: o radar mede os sinais vitais de uma só pessoa.",
    too_many_areas: (area, maxAreas) => `O radar guarda no máximo ${maxAreas} áreas.`,
};

/**
 * Os avisos de um `invalid_radar_areas`: o `items[i]` é a área `i` do que se enviou.
 *
 * @returns {Array<{key?: number, text: string}>}
 */
export function areaWarnings(result, sent, maxAreas) {
    if (result?.error?.code !== "invalid_radar_areas") return [];

    return Object.entries(result.error.fields ?? {}).flatMap(([field, codes]) => {
        const area = sent[Number(field.match(/^items\[(\d+)\]$/)?.[1])];
        return codes.map((code) => ({ key: area?.key, text: RULE_TEXT[code]?.(area ?? {}, maxAreas) ?? "Esta área não cumpre as regras do radar." }));
    });
}
