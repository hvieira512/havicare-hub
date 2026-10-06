import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { uplinkCardContent } = await import(
    "../../src/Dashboard/dashboard/components/cards/telemetry.js",
);

/**
 * Uma coluna por dose marcada, identificada pela hora que está no plano de medicação; os slots
 * por marcar colapsam num só.
 */
const PLAN = {
    plans: [
        { times: [{ time: "09:35", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
        { times: [{ time: "09:50", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
        { times: [{ time: "10:10", enabled: true, slot: 3, recurrence: { kind: "daily" } }] },
        { times: [{ time: "20:00", enabled: true, slot: 4, recurrence: { kind: "daily" } }] },
    ],
};

const NINE = (overrides = {}) =>
    Array.from({ length: 9 }, (_unused, index) => ({
        alarm: index + 1,
        state: overrides[index + 1] || "idle",
    }));

const strip = (data) =>
    parseFragment(uplinkCardContent("medication_alarm_status", data).body || "");

beforeEach(() => {
    state.selectedDetail = { effectiveConfigurations: { medication_reminders: PLAN } };
});

test("cada dose marcada é uma coluna, com a hora dela", () => {
    const doses = strip({
        takenCount: 2,
        missedCount: 1,
        alarms: NINE({ 1: "taken", 2: "taken", 3: "missed", 4: "waiting" }),
    }).querySelectorAll("[data-dose-slot]");

    assert.equal(doses.length, 4, "quatro marcadas, e as cinco livres não contam como colunas");
    assert.match(doses[0].textContent, /09:35/);
    assert.match(doses[3].textContent, /20:00/);
});

test("a ordem é a das horas e não a dos números de alarme", () => {
    state.selectedDetail.effectiveConfigurations.medication_reminders = {
        plans: [
            { times: [{ time: "20:00", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
            { times: [{ time: "08:00", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
        ],
    };

    const doses = strip({ takenCount: 0, missedCount: 0, alarms: NINE() })
        .querySelectorAll("[data-dose-slot]");

    assert.equal(doses[0].dataset.doseSlot, "2");
    assert.match(doses[0].textContent, /08:00/);
});

test("o estado de cada dose vai na coluna dela", () => {
    const doses = strip({
        takenCount: 1,
        missedCount: 1,
        alarms: NINE({ 1: "taken", 3: "missed" }),
    }).querySelectorAll("[data-dose-slot]");

    assert.equal(doses[0].dataset.doseState, "taken");
    assert.equal(doses[2].dataset.doseState, "missed");
    assert.match(doses[2].textContent, /[Ff]alhada/);
});

test("os slots por marcar colapsam num só", () => {
    const free = strip({ takenCount: 0, missedCount: 0, alarms: NINE() })
        .querySelector("[data-dose-free]");

    assert.ok(free, "devia haver uma coluna a resumir os livres");
    assert.match(free.textContent, /5/);
    assert.match(free.textContent, /9/);
});

/**
 * Sem plano lido, a hora não se inventa: fica o número do alarme, que é o que o aparelho
 * garantidamente disse.
 */
test("sem plano sincronizado, a coluna vale pelo número do alarme", () => {
    state.selectedDetail = {};

    const doses = strip({
        takenCount: 1,
        missedCount: 0,
        alarms: NINE({ 4: "taken" }),
    }).querySelectorAll("[data-dose-slot]");

    assert.equal(doses.length, 1);
    assert.equal(doses[0].dataset.doseSlot, "4");
    assert.match(doses[0].textContent, /Alarme 4/);
});

/** O cartão ocupa a linha toda: em meia, as colunas teriam quarenta pixéis cada. */
test("o cartão pede a linha inteira", () => {
    assert.equal(
        uplinkCardContent("medication_alarm_status", {
            takenCount: 0,
            missedCount: 0,
            alarms: NINE(),
        }).span,
        12,
    );
});

/** E a mudança de uma dose é um evento, com a sua própria leitura de uma linha. */
test("a alteração de uma dose diz a hora e o estado novo", () => {
    const content = uplinkCardContent("medication_alarm_change", { alarm: 3, state: "missed" });

    assert.match(content.value, /10:10/);
    assert.match(content.value, /[Ff]alhada/);
});
