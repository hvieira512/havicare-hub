import test from "node:test";
import assert from "node:assert/strict";

import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * Os eventos de um sistema de chamada de enfermagem. O que distingue um do seguinte não é o
 * tipo -- há só dois -- mas o comando que o disparou, e é esse que a linha tem de mostrar.
 */

test("a help call says which remote made it", () => {
    const card = uplinkCardContent("help_call", { pagerId: "348319" });

    assert.equal(card.value, "Chamada de ajuda");
    // O nome da linha já é "Chamada de ajuda": repeti-lo na coluna do valor não acrescenta
    // nada, e é ali que o comando cabe.
    assert.equal(card.rowValue, "Pager 348319");
});

test("the cancellation names the same remote", () => {
    const card = uplinkCardContent("reset", { pagerId: "348319" });

    assert.equal(card.value, "Cancelado");
    assert.equal(card.rowValue, "Pager 348319");
});

test("without an identified remote the value column stays empty instead of repeating the name", () => {
    for (const data of [{}, { pagerId: "" }]) {
        assert.equal(uplinkCardContent("help_call", data).rowValue, undefined);
        assert.equal(uplinkCardContent("reset", data).rowValue, undefined);
    }
});

test("a bracelet that declares its press mode still says it", () => {
    // A W6B passa pelo mesmo renderizador e não tem pager: o que a distingue é o toque.
    const card = uplinkCardContent("help_call", { pressType: "double" });

    assert.equal(card.value, "Chamada de ajuda (toque duplo)");
    assert.equal(card.rowValue, undefined);
});
