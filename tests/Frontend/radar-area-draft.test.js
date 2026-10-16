import test from "node:test";
import assert from "node:assert/strict";

import {
    addArea,
    areaStatus,
    areaWarnings,
    areasFromLayout,
    draftItems,
    firstFreeKey,
    moveArea,
    resizeArea,
    toggleDelete,
} from "../../src/Dashboard/dashboard/devices/radar-area-draft.js";

const ROOM = { xMinDm: -30, yMinDm: -7, xMaxDm: 19, yMaxDm: 18 };
const area = (key, type, name, xMinDm, yMinDm, xMaxDm, yMaxDm) => ({ key, type, name, xMinDm, yMinDm, xMaxDm, yMaxDm });
const BED = area(0, 5, "Cama", -4, -6, 6, 13);
const DOOR = area(1, 4, "Porta", 17, 15, 20, 18);

test("a planta guardada passa para o formato do contrato", () => {
    const { room, areas } = areasFromLayout({
        room: { x_min_dm: -30, y_min_dm: -7, x_max_dm: 19, y_max_dm: 18 },
        areas: [{ key: 0, type: 5, name: "Cama", x_min_dm: -4, y_min_dm: -6, x_max_dm: 6, y_max_dm: 13 }],
    });

    assert.deepEqual(room, ROOM);
    assert.deepEqual(areas, [BED]);
});

test("uma área nova fica com o primeiro key livre de 0 a 15", () => {
    assert.equal(firstFreeKey([BED, area(3, 1, "Mesa", 0, 0, 5, 5)]), 1);
    assert.equal(firstFreeKey(Array.from({ length: 16 }, (_, key) => area(key, 1, "", 0, 0, 1, 1))), null);
});

test("a área nova nasce com o nome do tipo, centrada e no encaixe de 10 cm", () => {
    const areas = addArea([BED], 4, [2.4, 3.6]);
    const door = areas.at(-1);

    assert.equal(door.key, 1);
    assert.equal(door.name, "Porta");
    assert.ok(Number.isInteger(door.xMinDm) && Number.isInteger(door.yMaxDm));
    assert.equal(Math.round((door.xMinDm + door.xMaxDm) / 2), 2);
});

test("o estado de cada área compara-se com a planta do radar", () => {
    const originals = [BED, DOOR];

    assert.equal(areaStatus(BED, originals), "applied");
    assert.equal(areaStatus({ ...BED, name: "Cama do pai" }, originals), "edited");
    assert.equal(areaStatus(area(2, 1, "Mesa", 0, 0, 5, 5), originals), "new");
    assert.equal(areaStatus({ ...DOOR, deleted: true }, originals), "deleted");
});

test("apagar uma área do radar só a marca; apagar uma nova tira-a", () => {
    const originals = [BED];
    const fresh = area(1, 1, "Mesa", 0, 0, 5, 5);

    assert.deepEqual(toggleDelete([BED, fresh], 0, originals).map((a) => [a.key, Boolean(a.deleted)]), [[0, true], [1, false]]);
    assert.deepEqual(toggleDelete([BED, fresh], 1, originals).map((a) => a.key), [0]);
    assert.equal(toggleDelete(toggleDelete([BED], 0, originals), 0, originals)[0].deleted, false);
});

test("o envio leva todas as áreas que ficam, com o key de cada uma", () => {
    const items = draftItems([BED, { ...DOOR, deleted: true }, area(2, 1, "Mesa", 0, 0, 5, 5)]);

    assert.deepEqual(items, [BED, area(2, 1, "Mesa", 0, 0, 5, 5)]);
});

test("mover e redimensionar encaixam ao decímetro e não deixam a área sumir", () => {
    assert.deepEqual(moveArea(BED, 1.4, -0.6), { ...BED, xMinDm: -3, yMinDm: -7, xMaxDm: 7, yMaxDm: 12 });
    assert.deepEqual(resizeArea(BED, "11", 2.2, -50), { ...BED, xMaxDm: 8, yMaxDm: -5 });
    assert.deepEqual(resizeArea(BED, "00", -1, 0), { ...BED, xMinDm: -5 });
});

const warnings = (areas) => areaWarnings(areas, ROOM);

test("uma área que sai dos limites de deteção avisa", () => {
    const list = warnings([area(3, 6, "Casa de Banho", -31, 1, -25, 4)]);

    assert.equal(list.length, 1);
    assert.equal(list[0].hard, true);
    assert.match(list[0].text, /Casa de Banho.*0,1 m/);
});

test("uma porta tem de estar encostada à parede", () => {
    assert.deepEqual(warnings([DOOR]), []);
    assert.match(warnings([area(1, 4, "Porta", 0, 0, 3, 3)])[0].text, /parede/);
});

test("uma interferência por cima da cama é aviso suave", () => {
    const list = warnings([BED, area(2, 3, "Ventoinha", 0, 0, 3, 3)]);

    assert.equal(list.length, 1);
    assert.equal(list[0].hard, false);
});

test("mais do que uma cama de monitorização é aviso suave", () => {
    const list = warnings([BED, { ...BED, key: 2, xMinDm: -20, xMaxDm: -10 }]);

    assert.equal(list.length, 1);
    assert.equal(list[0].hard, false);
});

test("mais de 16 áreas não cabem no radar", () => {
    const many = Array.from({ length: 17 }, (_, key) => area(key, 1, `A${key}`, 0, 0, 1, 1));

    assert.ok(warnings(many).some((warning) => warning.hard && /16/.test(warning.text)));
});

test("as áreas a apagar não contam para os avisos", () => {
    assert.deepEqual(warnings([{ ...area(3, 6, "Fora", -40, 1, -35, 4), deleted: true }]), []);
});
