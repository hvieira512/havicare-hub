import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/telemetry-cards.js";

/**
 * Os eventos de um sistema de chamada de enfermagem. O que distingue um do seguinte não é o
 * tipo -- há só dois -- mas o comando que o disparou, e é esse que a linha tem de mostrar.
 */

test("a chamada de ajuda diz que comando a fez", () => {
    const card = uplinkCardContent("help_call", { pagerId: "348319" });

    assert.equal(card.value, "Chamada de ajuda");
    // O nome da linha já é "Chamada de ajuda": repeti-lo na coluna do valor não acrescentava
    // nada, e era o único sítio onde o comando cabia.
    assert.equal(card.rowValue, "Pager 348319");
    assert.equal(card.icon, "fa-triangle-exclamation");
});

test("o cancelamento diz o mesmo comando", () => {
    const card = uplinkCardContent("reset", { pagerId: "348319" });

    assert.equal(card.value, "Cancelado");
    assert.equal(card.rowValue, "Pager 348319");
    assert.equal(card.icon, "fa-bell-slash");
});

test("sem comando identificado a coluna do valor fica vazia em vez de repetir o nome", () => {
    for (const data of [{}, { pagerId: "" }]) {
        assert.equal(uplinkCardContent("help_call", data).rowValue, undefined);
        assert.equal(uplinkCardContent("reset", data).rowValue, undefined);
    }
});

test("uma pulseira que declara o modo de toque continua a dizê-lo", () => {
    // A W6B passa pelo mesmo renderizador e não tem pager: o que a distingue é o toque.
    const card = uplinkCardContent("help_call", { pressType: "double" });

    assert.equal(card.value, "Chamada de ajuda (toque duplo)");
    assert.equal(card.rowValue, undefined);
});
