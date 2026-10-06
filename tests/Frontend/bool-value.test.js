import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { boolValue } from "../../src/Dashboard/dashboard/devices/config/normalizers.js";

/** O que um aparelho manda por «ligado» não é só `true` e `1`. */

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
