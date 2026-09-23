import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * Os nove alarmes do dispensador, desenhados.
 *
 * O cartão dizia «1 tomada» e listava em texto os alarmes que não estavam parados. Com nove
 * compartimentos e um estado em cada, uma lista de linhas é a forma errada: o que se quer
 * saber de relance é quais falharam e quais estão à espera, e isso lê-se numa grelha em que
 * cada posição é sempre a mesma.
 *
 * É a mesma decisão que a tira de canais da fralda: o corpo do cartão desenha, e o valor
 * continua a ser o número que se lê de longe.
 */
const grid = (data) => parseFragment(uplinkCardContent("medication_alarm_status", data).body || "");

const nineAlarms = (overrides = {}) =>
    Array.from({ length: 9 }, (_unused, index) => ({
        alarm: index + 1,
        state: overrides[index + 1] || "idle",
    }));

test("a grelha tem uma posição por alarme, sempre as nove", () => {
    const slots = grid({
        complete: true,
        takenCount: 1,
        missedCount: 0,
        alarms: nineAlarms({ 1: "taken" }),
    }).querySelectorAll("[data-alarm-slot]");

    assert.equal(slots.length, 9);
    assert.equal(slots[0].dataset.alarmSlot, "1");
    assert.equal(slots[8].dataset.alarmSlot, "9");
});

/** A cor é o estado, e é por ela que se lê a grelha sem ler número nenhum. */
test("cada posição leva o estado em que está", () => {
    const slots = grid({
        complete: true,
        takenCount: 1,
        missedCount: 1,
        alarms: nineAlarms({ 1: "taken", 2: "missed", 3: "waiting" }),
    }).querySelectorAll("[data-alarm-slot]");

    assert.equal(slots[0].dataset.alarmState, "taken");
    assert.equal(slots[1].dataset.alarmState, "missed");
    assert.equal(slots[2].dataset.alarmState, "waiting");
    assert.equal(slots[3].dataset.alarmState, "idle");
});

/** Quem passa o rato tem o nome do estado por extenso, que a cor sozinha não dá. */
test("cada posição diz por palavras o que a cor mostra", () => {
    const slots = grid({
        complete: true,
        takenCount: 0,
        missedCount: 1,
        alarms: nineAlarms({ 4: "missed" }),
    }).querySelectorAll("[data-alarm-slot]");

    assert.match(slots[3].getAttribute("title"), /Toma falhada/);
});

/** Uma leitura parcial não desenha uma grelha que não tem: traria oito alarmes inventados. */
test("uma notificação de um alarme só não desenha a grelha", () => {
    const content = uplinkCardContent("medication_alarm_status", {
        complete: false,
        alarms: [{ alarm: 3, state: "taken" }],
    });

    assert.equal(content.body || "", "");
});

/** O valor continua a ser o que se lê de longe, e a falha ganha-o à toma. */
test("o valor principal continua a contar", () => {
    assert.equal(
        uplinkCardContent("medication_alarm_status", {
            complete: true,
            takenCount: 2,
            missedCount: 1,
            alarms: nineAlarms({ 1: "taken", 2: "taken", 3: "missed" }),
        }).value,
        "1 falhada",
    );
});
