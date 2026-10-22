import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

const { pillDispenserAlarms: alarms, pillDispenserPeriod: period, pillDispenserQuietHours: quietHours } =
    CONFIG_INPUTS;

const entry = (fields) => ({ fields, limit: 9 });
const render = (input, desired) => parseFragment(input.render({ fields: [], limit: 9 }, desired));
const daily = (time, slot) => ({ times: [{ time, enabled: true, slot, recurrence: { kind: "daily" } }] });

test("the plan starts empty without breaking", () => {
    const html = String(alarms.render(entry(["plans"]), {}));

    assert.equal((html.match(/data-alarm-slot/g) || []).length, 9);
});

test("the plan period draws both dates and the switch", () => {
    const html = String(period.render(
        entry(["enabled", "startDate", "endDate"]),
        { enabled: true, startDate: "2026-09-18", endDate: "2026-12-31" },
    ));

    assert.match(html, /type="date"[^>]*data-config-field="startDate"/);
    assert.match(html, /data-config-field="endDate"/);
    assert.match(html, /2026-12-31/);
});

/** O número do alarme tem de ser o que chega ao aparelho: sem os vazios, escolher o 5 escreveria no 3. */
test("the chosen alarm carries its own number, not its list position", () => {
    const values = new Map([["time-4", "10:24"]]);
    const section = {
        querySelector: (selector) => {
            const field = selector.match(/\[data-config-field="([^"]+)"\]/)?.[1];
            if (field === undefined || !/^time-[0-8]$/.test(field)) return null;

            return { value: values.get(field) ?? "", checked: false };
        },
    };

    assert.deepEqual(alarms.read(section).plans, [daily("10:24", 5)]);
});

test("alarm 5 is drawn in box 5", () => {
    const cells = String(alarms.render({ limit: 9 }, { plans: [daily("10:24", 5)] }))
        .split("data-alarm-slot=\"")
        .slice(1);

    assert.equal(cells.length, 9);
    assert.match(cells[4], /value="10:24"/);
    assert.doesNotMatch(cells[0], /value="10:24"/);
});

/** Esta firmware ignora o `0x1041`--`0x1049`: um interruptor faria julgar calado o que toca. */
test("the card draws no switches", () => {
    assert.doesNotMatch(String(alarms.render({}, { plans: [daily("08:00", 1)] })), /type="checkbox"/);
});

test("a set alarm reads back with the chosen time", () => {
    const plans = [daily("08:30", 1), daily("20:05", 2)];

    assert.deepEqual(alarms.read(render(alarms, { plans })), { plans });
});

test("each compartment shows the name of its medicine", () => {
    const cells = render(alarms, { plans: [{ name: "Metformina", ...daily("10:24", 5) }] })
        .querySelectorAll("[data-alarm-slot]");

    assert.equal(cells[4].querySelector("input[type=text]").getAttribute("value"), "Metformina");
    assert.equal(cells[0].querySelector("input[type=text]").getAttribute("value"), "");
});

test("the name reads back with its compartment, and a blank one is left out", () => {
    const plans = [{ name: "Metformina", ...daily("08:30", 1) }, daily("20:05", 2)];

    assert.deepEqual(alarms.read(render(alarms, { plans })), { plans });
});

/** O `24` e o `60` são o sentinela do aparelho para um slot nunca definido. */
test("the device's sentinel times are not drawn as a time", () => {
    const root = render(alarms, { plans: [{ hour: 24, minute: 60 }] });

    assert.equal(root.querySelectorAll("input[type=time]")[0].getAttribute("value"), "");
});

/** Um slot desenhado em branco não volta como plano nenhum, que é o que o esvazia. */
test("a blank slot does not come back in the plan", () => {
    const root = render(alarms, { plans: [{ hour: 8, minute: 30 }] });

    root.querySelectorAll("input[type=time]")[0].setAttribute("value", "");

    assert.deepEqual(alarms.read(root), { plans: [] });
});

const QUIET = { enabled: true, startHour: 22, startMinute: 30, endHour: 7, endMinute: 5 };

test("quiet hours draw two time pickers", () => {
    const times = [...render(quietHours, QUIET).querySelectorAll("input[type=time]")];

    assert.deepEqual(times.map((el) => el.getAttribute("value")), ["22:30", "07:05"]);
});

/** Saem em hora e minuto separados, que é o que as TAGs levam. */
test("quiet hours read back split into hour and minute", () => {
    assert.deepEqual(quietHours.read(render(quietHours, QUIET)), QUIET);
});
