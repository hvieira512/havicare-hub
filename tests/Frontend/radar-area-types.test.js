import test from "node:test";
import assert from "node:assert/strict";

import { areaName, areaTypeStyle } from "../../src/Dashboard/dashboard/core/radar-style.js";

/** Os nomes vêm do `baseList` do console do fabricante. */
test("the area type shows the manufacturer's name for it", () => {
    assert.equal(areaTypeStyle("monitoring_bed").label, "Cama de monitorização");
    assert.equal(areaTypeStyle("furniture").label, "Mobília");
});

/** Um tipo novo do fabricante fica à vista, em vez de sumir da planta. */
test("a type the manufacturer adds shows as it came", () => {
    assert.equal(areaTypeStyle("sauna").label, "Tipo sauna");
});

/** O hub não inventa nomes: uma área sem nome do fabricante chega com `null`. */
test("an area without a name is called by its number", () => {
    assert.equal(areaName({ key: 2, name: null }), "Área 2");
    assert.equal(areaName({ key: 2, name: "Porta" }), "Porta");
});
