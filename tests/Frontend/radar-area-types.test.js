import test from "node:test";
import assert from "node:assert/strict";

import { areaTypeStyle } from "../../src/Dashboard/dashboard/radar-style.js";

/** Os nomes vêm do `baseList` do console do fabricante. */
test("o tipo de área diz o nome que o fabricante lhe dá", () => {
    assert.equal(areaTypeStyle(5).label, "Cama de monitorização");
});

test("a chave chega como texto no payload e continua a ser reconhecida", () => {
    assert.equal(areaTypeStyle("7").label, "Mobília");
});

/** Um tipo novo do fabricante fica à vista com o número, em vez de sumir da planta. */
test("um tipo que o fabricante acrescente mostra o número", () => {
    assert.equal(areaTypeStyle(9).label, "Tipo 9");
});
