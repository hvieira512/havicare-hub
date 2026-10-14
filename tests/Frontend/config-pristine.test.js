import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { readConfigEntryValue } from "../../src/Dashboard/dashboard/devices/config/index.js";

/** A fotografia de uma lista tem de bater com o que o `select` devolve, ou a linha nasce alterada. */
const zone = { key: "time_zone", input: "select", fields: ["timeZone"] };

test("um fuso com nome fica como texto", () => {
    assert.deepEqual(readConfigEntryValue(zone, { timeZone: "Europe/Lisbon" }), { timeZone: "Europe/Lisbon" });
});

test("um desvio numérico continua número", () => {
    assert.deepEqual(readConfigEntryValue(zone, { timeZone: 100 }), { timeZone: 100 });
});
