import test from "node:test";
import assert from "node:assert/strict";

import { cardContent } from "./support/cards.js";
import { fieldValue } from "../../src/Dashboard/dashboard/format.js";

/** Cada cartão lê o campo do contrato, que traz a unidade no nome. */
test("the bracelet cards read the named quantities", () => {
    assert.equal(cardContent("hrv", { hrvMilliseconds: 42 }).value, "42 ms");
    assert.equal(cardContent("met", { met: 0.9 }).value, "0.9 MET");
    assert.equal(cardContent("stress", { stressScore: 33 }).value, "33");
    assert.equal(cardContent("cardiac_load", { cardiacLoad: 7 }).value, "7");
    assert.equal(cardContent("steps", { steps: 37, periodSeconds: 300 }).value, "37 passos");
});

test("the R-R card averages the intervals", () => {
    const data = { intervals: [
        { measuredAt: "2026-09-09T03:20:00Z", rrIntervalMilliseconds: 800 },
        { measuredAt: "2026-09-09T03:20:06Z", rrIntervalMilliseconds: 820 },
    ] };

    assert.equal(cardContent("rr_interval", data).value, "2 × 810 ms");
});

test("a location heading shows in degrees", () => {
    assert.equal(fieldValue("headingDegrees", 152), "152 °");
});
