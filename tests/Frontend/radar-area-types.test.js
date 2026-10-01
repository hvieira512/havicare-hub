import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { areaTypeStyle } from "../../src/Dashboard/dashboard/radar-style.js";

/** Os tipos de área como o `baseList` do console do fabricante os enumera. */
const DECLARED = [
    [1, "Personalizada"],
    [2, "Cama"],
    [3, "Interferência"],
    [4, "Porta"],
    [5, "Cama de monitorização"],
    [6, "Região de alarme"],
    [7, "Mobília"],
];

for (const [type, label] of DECLARED) {
    test(`o tipo de área ${type} chama-se «${label}»`, () => {
        assert.equal(areaTypeStyle(type).label, label);
    });
}

test("a chave chega como texto no payload e continua a ser reconhecida", () => {
    assert.equal(areaTypeStyle("7").label, "Mobília");
});

/** Um tipo novo do fabricante fica à vista com o número, em vez de sumir da planta. */
test("um tipo que o fabricante acrescente mostra o número", () => {
    assert.equal(areaTypeStyle(9).label, "Tipo 9");
});
