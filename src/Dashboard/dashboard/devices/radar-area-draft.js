import { areaTypeStyle } from "../radar-style.js";

/**
 * O rascunho das áreas de um radar, em decímetros e no formato do contrato (`xMinDm`…). O `key`
 * é o `regionId` da telemetria: uma área mantém-no enquanto existir.
 */

export const MAX_AREAS = 16;

/** Os tipos que se criam, pela ordem do menu, com o tamanho com que nascem (largura × comprimento). */
export const CREATABLE_TYPES = [
    { type: 5, size: [10, 19] },
    { type: 2, size: [9, 19] },
    { type: 4, size: [8, 3] },
    { type: 6, size: [8, 8] },
    { type: 3, size: [6, 6] },
    { type: 1, size: [5, 5] },
];

const DOOR = 4;
const INTERFERENCE = 3;
const BEDS = [2, 5];
const MONITORING_BED = 5;
/** A porta conta como encostada até 20 cm da parede. */
const DOOR_WALL_TOLERANCE_DM = 2;

const box = (source) => ({
    xMinDm: Number(source.x_min_dm),
    yMinDm: Number(source.y_min_dm),
    xMaxDm: Number(source.x_max_dm),
    yMaxDm: Number(source.y_max_dm),
});

/** O `radar-layout` da API vem em snake_case; o rascunho fala a língua do contrato. */
export function areasFromLayout(layout) {
    return {
        room: layout?.room ? box(layout.room) : null,
        areas: (layout?.areas ?? []).map((area) => ({
            key: Number(area.key),
            type: Number(area.type),
            name: String(area.name ?? ""),
            ...box(area),
        })),
    };
}

export function firstFreeKey(areas) {
    const used = new Set(areas.map((area) => area.key));
    for (let key = 0; key < MAX_AREAS; key += 1) {
        if (!used.has(key)) return key;
    }
    return null;
}

/** Acrescenta uma área do tipo pedido centrada em `[x, y]`; sem `key` livre, fica como estava. */
export function addArea(areas, type, [x, y]) {
    const key = firstFreeKey(areas);
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

/** Uma área que está no radar fica marcada e sai no envio; uma que só existe no rascunho sai já. */
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

export const metres = (dm) => (dm / 10).toLocaleString("pt-PT", { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const overlaps = (a, b) => a.xMinDm < b.xMaxDm && a.xMaxDm > b.xMinDm && a.yMinDm < b.yMaxDm && a.yMaxDm > b.yMinDm;

/**
 * O que o radar não vai fazer como se espera. `hard` é o que está mal; o resto é aviso suave.
 *
 * @returns {Array<{key?: number, hard: boolean, text: string}>}
 */
export function areaWarnings(areas, room) {
    const live = areas.filter((area) => !area.deleted);
    const list = [];

    for (const area of live) {
        if (area.type === DOOR) {
            const wall = Math.min(
                Math.abs(area.xMinDm - room.xMinDm),
                Math.abs(area.xMaxDm - room.xMaxDm),
                Math.abs(area.yMinDm - room.yMinDm),
                Math.abs(area.yMaxDm - room.yMaxDm),
            );
            if (wall > DOOR_WALL_TOLERANCE_DM) {
                list.push({ key: area.key, hard: true, text: `A porta «${area.name}» está a ${metres(wall)} m da parede mais próxima; tem de ficar encostada.` });
            }
            continue;
        }
        const outX = Math.max(0, room.xMinDm - area.xMinDm, area.xMaxDm - room.xMaxDm);
        const outY = Math.max(0, room.yMinDm - area.yMinDm, area.yMaxDm - room.yMaxDm);
        if (outX > 0 || outY > 0) {
            list.push({ key: area.key, hard: true, text: `«${area.name}» sai ${metres(Math.max(outX, outY))} m dos limites de deteção; o radar não vê essa parte.` });
        }
    }

    for (const interference of live.filter((area) => area.type === INTERFERENCE)) {
        if (live.some((area) => BEDS.includes(area.type) && overlaps(interference, area))) {
            list.push({ key: interference.key, hard: false, text: `A interferência «${interference.name}» cobre parte da cama: aí não há alarmes.` });
        }
    }

    const monitoring = live.filter((area) => area.type === MONITORING_BED);
    if (monitoring.length > 1) {
        list.push({ key: monitoring[1].key, hard: false, text: "Há mais do que uma cama de monitorização; o radar mede os sinais vitais de uma só pessoa." });
    }

    if (live.length > MAX_AREAS) {
        list.push({ hard: true, text: `O radar guarda no máximo ${MAX_AREAS} áreas.` });
    }

    return list;
}
