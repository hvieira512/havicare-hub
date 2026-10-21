import test from "node:test";
import assert from "node:assert/strict";

import { runoutAt, runoutLabel } from "../../src/Dashboard/dashboard/devices/medication-runout.js";

// Quinta, 1 de outubro de 2026, depois dos dois alarmes do dia já terem tocado.
const now = new Date(2026, 9, 1, 10, 52);
const plans = [
    { times: [{ time: "10:47", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
    { times: [{ time: "10:50", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
];

test("the date comes from walking the plan's alarms, not from a division", () => {
    // Três por dispensar, dois alarmes por dia: amanhã às 10:47, amanhã às 10:50, e a
    // terceira no dia seguinte.
    assert.deepEqual(runoutAt({ doses: 3, plans, now }), new Date(2026, 9, 3, 10, 47));
});

test("alarms still due today count before moving to tomorrow", () => {
    const morning = new Date(2026, 9, 1, 8, 0);

    assert.deepEqual(runoutAt({ doses: 1, plans, now: morning }), new Date(2026, 9, 1, 10, 47));
    assert.deepEqual(runoutAt({ doses: 3, plans, now: morning }), new Date(2026, 9, 2, 10, 47));
});

test("an alarm at exactly now no longer counts for today", () => {
    const atAlarmTime = new Date(2026, 9, 1, 10, 47);

    assert.deepEqual(runoutAt({ doses: 1, plans, now: atAlarmTime }), new Date(2026, 9, 1, 10, 50));
});

test("without a plan there is no pace, and without a pace no date", () => {
    assert.equal(runoutAt({ doses: 3, plans: [], now }), null);
    assert.equal(runoutAt({ doses: 3, plans: undefined, now }), null);
});

test("without doses to dispense there is no date to give", () => {
    assert.equal(runoutAt({ doses: 0, plans, now }), null);
    assert.equal(runoutAt({ doses: null, plans, now }), null);
});

/** O `0x100A` suspende o plano fora do período: esses alarmes nunca chegam a tocar. */
test("a period that ends before the doses gives no date", () => {
    const period = { enabled: true, startDate: "2026-10-01", endDate: "2026-10-02" };

    assert.equal(runoutAt({ doses: 3, plans, period, now }), null);
    assert.deepEqual(runoutAt({ doses: 2, plans, period, now }), new Date(2026, 9, 2, 10, 50));
});

test("a disabled period limits nothing", () => {
    const period = { enabled: false, startDate: "2026-10-01", endDate: "2026-10-02" };

    assert.deepEqual(runoutAt({ doses: 3, plans, period, now }), new Date(2026, 9, 3, 10, 47));
});

test("a plan that has not started counts from the period start", () => {
    const period = { enabled: true, startDate: "2026-10-05", endDate: "2026-12-31" };

    assert.deepEqual(runoutAt({ doses: 1, plans, period, now }), new Date(2026, 9, 5, 10, 47));
});

test("disabled plan alarms use no cells", () => {
    const withDisabledAlarm = [
        { times: [{ time: "10:47", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
        { times: [{ time: "10:50", enabled: false, slot: 2, recurrence: { kind: "daily" } }] },
    ];

    assert.deepEqual(runoutAt({ doses: 2, plans: withDisabledAlarm, now }), new Date(2026, 9, 3, 10, 47));
});

test("the label is Portuguese and calls today and tomorrow by name", () => {
    assert.equal(runoutLabel(new Date(2026, 9, 1, 18, 30), now), "hoje às 18:30");
    assert.equal(runoutLabel(new Date(2026, 9, 2, 10, 47), now), "amanhã às 10:47");
    assert.equal(runoutLabel(new Date(2026, 9, 3, 10, 47), now), "sáb., 3 out. às 10:47");
    assert.equal(runoutLabel(new Date(2026, 10, 14, 9, 5), now), "sáb., 14 nov. às 09:05");
    assert.equal(runoutLabel(null, now), "");
});

test("a past date says it has already run out", () => {
    assert.equal(runoutLabel(new Date(2026, 8, 30, 10, 50), now), "ontem às 10:50");
    assert.equal(runoutLabel(new Date(2026, 8, 28, 10, 50), now), "seg., 28 set. às 10:50");
});
