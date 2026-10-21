import test from "node:test";
import assert from "node:assert/strict";

import {
    addArea,
    areaStatus,
    areaWarnings,
    draftItems,
    firstFreeKey,
    moveArea,
    resizeArea,
    toggleDelete,
} from "../../src/Dashboard/dashboard/devices/radar-area-draft.js";

const area = (key, type, name, xMinDm, yMinDm, xMaxDm, yMaxDm) => ({ key, type, name, xMinDm, yMinDm, xMaxDm, yMaxDm });
const BED = area(0, "monitoring_bed", "Cama", -4, -6, 6, 13);
const DOOR = area(1, "door", "Porta", 17, 15, 20, 18);

test("a new area takes the first free key from 0 to 15", () => {
    assert.equal(firstFreeKey([BED, area(3, "custom", "Mesa", 0, 0, 5, 5)], 16), 1);
    assert.equal(firstFreeKey(Array.from({ length: 16 }, (_, key) => area(key, "custom", "", 0, 0, 1, 1)), 16), null);
});

test("a new area starts with the type's name, centred and snapped to 10 cm", () => {
    const areas = addArea([BED], "door", [2.4, 3.6], 16);
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

test("the API refusal turns into one warning per area, by the key of what was sent", () => {
    const sent = [BED, area(4, "door", "Porta", 0, 0, 3, 3)];
    const list = areaWarnings({
        error: { code: "invalid_radar_areas", fields: { "items[1]": ["door_off_wall"], items: ["too_many_areas"] } },
    }, sent, 16);

    assert.deepEqual(list.map((warning) => warning.key), [4, undefined]);
    assert.match(list[0].text, /Porta.*parede/);
    assert.match(list[1].text, /16/);
});

test("any other refusal leaves the plan without warnings", () => {
    assert.deepEqual(areaWarnings({ error: { code: "invalid_config", fields: { radar_areas: ["x"] } } }, [BED], 16), []);
});
