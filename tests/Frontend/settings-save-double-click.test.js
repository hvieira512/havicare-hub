import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { whileBusy } from "../../src/Dashboard/dashboard/settings/row-editor.js";

/**
 * Dois cliques seguidos em «Guardar» numa linha nova criavam dois registos iguais: o pedido
 * viaja e o botão continua a aceitar cliques.
 */
function deferred() {
    let settle;
    const promise = new Promise((resolve) => {
        settle = resolve;
    });

    return { promise, settle };
}

test("o segundo clique não corre enquanto o primeiro não acabar", async () => {
    const button = document.createElement("button");
    const pending = deferred();
    let runs = 0;

    const count = (result) => {
        runs += 1;
        return result;
    };

    const first = whileBusy(button, () => count(pending.promise));
    const second = whileBusy(button, () => count(Promise.resolve()));

    assert.equal(runs, 1);
    assert.equal(button.disabled, true);

    pending.settle();
    await Promise.all([first, second]);

    assert.equal(runs, 1);
    assert.equal(button.disabled, false, "o botão volta a aceitar cliques");
});

test("uma falha não deixa o botão desligado para sempre", async () => {
    const button = document.createElement("button");

    await whileBusy(button, () => Promise.reject(new Error("falhou"))).catch(() => {});

    assert.equal(button.disabled, false);
});
