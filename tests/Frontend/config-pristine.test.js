import test from "node:test";
import assert from "node:assert/strict";

import { readConfigEntryValue } from "../../src/Dashboard/dashboard/devices/config/index.js";

/** A fotografia de uma lista tem de bater com o que o `select` devolve, ou a linha nasce alterada. */
const zone = { key: "time_zone", input: "select", fields: ["timeZone"] };

test("a named time zone stays as text", () => {
    assert.deepEqual(readConfigEntryValue(zone, { timeZone: "Europe/Lisbon" }), { timeZone: "Europe/Lisbon" });
});

test("a numeric offset stays a number", () => {
    assert.deepEqual(readConfigEntryValue(zone, { timeZone: 100 }), { timeZone: 100 });
});
