import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { uplinkCardContent } = await import(
    "../../src/Dashboard/dashboard/components/cards/telemetry.js",
);

/**
 * A faixa do dia: uma coluna por dose marcada, na ordem das horas.
 *
 * O que identifica uma dose é a hora dela, que está no plano de medicação que o hub já guarda;
 * o número do alarme fica pequeno ao lado, para quando for preciso falar do aparelho.
 *
 * Os slots por marcar colapsam num só: nove posições a dizer «Sem toma marcada» são nove
 * posições a dizer nada.
 */
const PLAN = {
    plans: [
        { slot: 1, hour: 9, minute: 35, enabled: true },
        { slot: 2, hour: 9, minute: 50, enabled: true },
        { slot: 3, hour: 10, minute: 10, enabled: true },
        { slot: 4, hour: 20, minute: 0, enabled: true },
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
            { slot: 1, hour: 20, minute: 0, enabled: true },
            { slot: 2, hour: 8, minute: 0, enabled: true },
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

/** O cartão ocupa a linha toda: em meia, as colunas ficavam com quarenta pixéis cada. */
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
