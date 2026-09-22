import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { boolValue } from "../../src/Dashboard/dashboard/devices/config/normalizers.js";

/**
 * O que um aparelho manda por «ligado» não é só `true` e `1`.
 *
 * Havia dois leitores com este nome e semânticas diferentes: este, que só conhecia
 * `true`/`1`/`"1"`, e um privado do editor de tomas do 4P Touch, que também lia `"true"`,
 * `"yes"` e `"on"`. Com dois nomes iguais e regras diferentes no mesmo subsistema, o valor
 * que um lia como desligado o outro lia como o que o `fallback` mandasse.
 */

test("os valores canónicos decidem, venham como booleano, número ou texto", () => {
    for (const on of [true, 1, "1"]) {
        assert.equal(boolValue(on, false), true, JSON.stringify(on));
    }
    for (const off of [false, 0, "0"]) {
        assert.equal(boolValue(off, true), false, JSON.stringify(off));
    }
});

test("as palavras que os aparelhos mandam também decidem", () => {
    for (const on of ["true", "yes", "on", "TRUE", " on "]) {
        assert.equal(boolValue(on, false), true, JSON.stringify(on));
    }
    for (const off of ["false", "no", "off", "OFF"]) {
        assert.equal(boolValue(off, true), false, JSON.stringify(off));
    }
});

/** Um número diferente de zero é ligado: há aparelhos que mandam o nível em vez do bit. */
test("um número qualquer diferente de zero é ligado", () => {
    assert.equal(boolValue(2, false), true);
    assert.equal(boolValue(-1, false), true);
});

test("o que não decide nada fica no valor por omissão", () => {
    for (const unknown of [undefined, null, "", "talvez", {}]) {
        assert.equal(boolValue(unknown, true), true, JSON.stringify(unknown));
        assert.equal(boolValue(unknown, false), false, JSON.stringify(unknown));
    }
});
