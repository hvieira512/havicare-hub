import test from "node:test";
import assert from "node:assert/strict";

import { areaTypeStyle } from "../../src/Dashboard/dashboard/radar-style.js";

/** Os nomes vêm do `baseList` do console do fabricante. */
test("the area type shows the manufacturer's name for it", () => {
    assert.equal(areaTypeStyle(5).label, "Cama de monitorização");
});

test("a key that arrives as text in the payload is still recognised", () => {
    assert.equal(areaTypeStyle("7").label, "Mobília");
});

/** Um tipo novo do fabricante fica à vista com o número, em vez de sumir da planta. */
test("a type the manufacturer adds shows its number", () => {
    assert.equal(areaTypeStyle(9).label, "Tipo 9");
});
