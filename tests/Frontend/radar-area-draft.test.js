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
const BED = area(0, "monitoring_bed", "Cama", -4, -6, 6, 13);
const DOOR = area(1, "door", "Porta", 17, 15, 20, 18);

test("the stored layout converts to the contract shape", () => {
    const { room, areas } = areasFromLayout({
        room: { xMinDm: -30, yMinDm: -7, xMaxDm: 19, yMaxDm: 18 },
        areas: [
            { key: 0, type: "monitoring_bed", name: "Cama", xMinDm: -4, yMinDm: -6, xMaxDm: 6, yMaxDm: 13 },
            { key: 3, type: "custom", name: null, xMinDm: 0, yMinDm: 0, xMaxDm: 5, yMaxDm: 5 },
        ],
    });

    assert.deepEqual(room, ROOM);
    assert.deepEqual(areas, [BED, area(3, "custom", "Área 3", 0, 0, 5, 5)]);
});

test("a new area takes the first free key from 0 to 15", () => {
    assert.equal(firstFreeKey([BED, area(3, "custom", "Mesa", 0, 0, 5, 5)]), 1);
    assert.equal(firstFreeKey(Array.from({ length: 16 }, (_, key) => area(key, "custom", "", 0, 0, 1, 1))), null);
});

test("a new area starts with the type's name, centred and snapped to 10 cm", () => {
    const areas = addArea([BED], "door", [2.4, 3.6]);
    const door = areas.at(-1);

    assert.equal(door.key, 1);
    assert.equal(door.name, "Porta");
    assert.ok(Number.isInteger(door.xMinDm) && Number.isInteger(door.yMaxDm));
    assert.equal(Math.round((door.xMinDm + door.xMaxDm) / 2), 2);
});

test("each area's state is compared with the radar's layout", () => {
    const originals = [BED, DOOR];

    assert.equal(areaStatus(BED, originals), "applied");
    assert.equal(areaStatus({ ...BED, name: "Cama do pai" }, originals), "edited");
    assert.equal(areaStatus(area(2, "custom", "Mesa", 0, 0, 5, 5), originals), "new");
    assert.equal(areaStatus({ ...DOOR, deleted: true }, originals), "deleted");
});

test("deleting a radar area only marks it; deleting a new one removes it", () => {
    const originals = [BED];
    const fresh = area(1, "custom", "Mesa", 0, 0, 5, 5);

    assert.deepEqual(toggleDelete([BED, fresh], 0, originals).map((a) => [a.key, Boolean(a.deleted)]), [[0, true], [1, false]]);
    assert.deepEqual(toggleDelete([BED, fresh], 1, originals).map((a) => a.key), [0]);
    assert.equal(toggleDelete(toggleDelete([BED], 0, originals), 0, originals)[0].deleted, false);
});

test("the submission carries every remaining area with its key", () => {
    const items = draftItems([BED, { ...DOOR, deleted: true }, area(2, "custom", "Mesa", 0, 0, 5, 5)]);

    assert.deepEqual(items, [BED, area(2, "custom", "Mesa", 0, 0, 5, 5)]);
});

test("moving and resizing snap to the decimetre and never make the area vanish", () => {
    assert.deepEqual(moveArea(BED, 1.4, -0.6), { ...BED, xMinDm: -3, yMinDm: -7, xMaxDm: 7, yMaxDm: 12 });
    assert.deepEqual(resizeArea(BED, "11", 2.2, -50), { ...BED, xMaxDm: 8, yMaxDm: -5 });
    assert.deepEqual(resizeArea(BED, "00", -1, 0), { ...BED, xMinDm: -5 });
});

const warnings = (areas) => areaWarnings(areas, ROOM);

test("an area outside the detection limits warns", () => {
    const list = warnings([area(3, "alarm_area", "Casa de Banho", -31, 1, -25, 4)]);

    assert.equal(list.length, 1);
    assert.equal(list[0].hard, true);
    assert.match(list[0].text, /Casa de Banho.*0,1 m/);
});

test("a door has to touch a wall", () => {
    assert.deepEqual(warnings([DOOR]), []);
    assert.match(warnings([area(1, "door", "Porta", 0, 0, 3, 3)])[0].text, /parede/);
});

test("interference over the bed is a soft warning", () => {
    const list = warnings([BED, area(2, "interference", "Ventoinha", 0, 0, 3, 3)]);

    assert.equal(list.length, 1);
    assert.equal(list[0].hard, false);
});

test("more than one monitoring bed is a soft warning", () => {
    const list = warnings([BED, { ...BED, key: 2, xMinDm: -20, xMaxDm: -10 }]);

    assert.equal(list.length, 1);
    assert.equal(list[0].hard, false);
});

test("more than 16 areas do not fit the radar", () => {
    const many = Array.from({ length: 17 }, (_, key) => area(key, "custom", `A${key}`, 0, 0, 1, 1));

    assert.ok(warnings(many).some((warning) => warning.hard && /16/.test(warning.text)));
});

test("areas marked for deletion do not count for warnings", () => {
    assert.deepEqual(warnings([{ ...area(3, "alarm_area", "Fora", -40, 1, -35, 4), deleted: true }]), []);
});
