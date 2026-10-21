import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { uplinkCardContent } = await import(
    "../../src/Dashboard/dashboard/components/cards/telemetry.js",
);
const { requestCardShell } = await import(
    "../../src/Dashboard/dashboard/components/cards/request.js",
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

test("each scheduled dose is a column with its time", () => {
    const doses = strip({
        takenCount: 2,
        missedCount: 1,
        alarms: NINE({ 1: "taken", 2: "taken", 3: "missed", 4: "waiting" }),
    }).querySelectorAll("[data-dose-slot]");

    assert.equal(doses.length, 4, "quatro marcadas, e as cinco livres não contam como colunas");
    assert.match(doses[0].textContent, /09:35/);
    assert.match(doses[3].textContent, /20:00/);
});

test("the order follows the times, not the alarm numbers", () => {
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

test("each dose's state goes in its column", () => {
    const doses = strip({
        takenCount: 1,
        missedCount: 1,
        alarms: NINE({ 1: "taken", 3: "missed" }),
    }).querySelectorAll("[data-dose-slot]");

    assert.equal(doses[0].dataset.doseState, "taken");
    assert.equal(doses[2].dataset.doseState, "missed");
    assert.match(doses[2].textContent, /[Ff]alhada/);
});

test("unscheduled slots collapse into one", () => {
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
test("without a synced plan, the column goes by alarm number", () => {
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

/** E a mudança de uma dose é um evento, com a sua própria leitura de uma linha. */
test("a dose change says the time and the new state", () => {
    const content = uplinkCardContent("medication_alarm_change", { alarm: 3, state: "missed" });

    assert.match(content.value, /10:10/);
    assert.match(content.value, /[Ff]alhada/);
});

/** O cartão inteiro, sem plano lido: os alarmes valem pelo número. */
const shell = (type, data) => {
    state.selectedDetail = { model: { deviceType: "pill_dispenser" } };
    return String(requestCardShell(
        { feature: type, requestable: false },
        false,
        [{ type, occurredAt: "2026-09-22T09:38:34Z", data }],
    ));
};
const card = (data) => shell("medication_alarm_status", data);

/** O estado dos nove alarmes traz uma lista, que o renderizador genérico não sabe desenhar. */
test("the alarm list never shows as [object Object]", () => {
    const rendered = card({
        takenCount: 1,
        missedCount: 1,
        alarms: [
            { alarm: 1, state: "taken" },
            { alarm: 3, state: "missed" },
        ],
    });

    assert.doesNotMatch(rendered, /\[object Object\]/);
});

/** Uma toma falhada é o que faz alguém olhar para o cartão, e por isso é o valor principal. */
test("a missed dose is the main value", () => {
    const rendered = card({
        takenCount: 2,
        missedCount: 1,
        alarms: [
            { alarm: 1, state: "taken" },
            { alarm: 2, state: "taken" },
            { alarm: 3, state: "missed" },
        ],
    });

    assert.match(rendered, /1 falhada/);
});

test("without misses, the main value is the doses taken", () => {
    const rendered = card({
        takenCount: 2,
        missedCount: 0,
        alarms: [
            { alarm: 1, state: "taken" },
            { alarm: 2, state: "taken" },
        ],
    });

    assert.match(rendered, /2 tomadas/);
    assert.doesNotMatch(rendered, /falhada/);
});

/** Nove linhas de «Sem toma marcada» não são detalhe nenhum: só os alarmes vivos entram. */
test("disabled alarms do not fill the card", () => {
    const rendered = card({
        takenCount: 0,
        missedCount: 0,
        alarms: [
            { alarm: 1, state: "idle" },
            { alarm: 2, state: "waiting" },
            { alarm: 3, state: "idle" },
        ],
    });

    assert.match(rendered, /Alarme 2/);
    assert.doesNotMatch(rendered, /Alarme 1/);
    assert.doesNotMatch(rendered, /Alarme 3/);
});

/** Nada em curso continua a ser uma leitura válida, e o cartão tem de a dizer em português. */
test("with everything disabled the card says there are no doses, in Portuguese", () => {
    const rendered = card({
        takenCount: 0,
        missedCount: 0,
        alarms: [{ alarm: 1, state: "idle" }],
    });

    assert.doesNotMatch(rendered, /TakenCount|MissedCount|Alarms/);
    assert.match(rendered, /Sem tomas/);
});

/**
 * A mudança de uma dose é capacidade própria: o cartão dos nove alarmes só recebe leituras dos
 * nove.
 */
test("a dose change is another capability and reads in one line", () => {
    const rendered = shell("medication_alarm_change", { alarm: 3, state: "missed" });

    assert.match(rendered, /Alarme 3/);
    assert.match(rendered, /falhada/i);
    assert.doesNotMatch(rendered, /\[object Object\]/);
});
