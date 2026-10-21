import test from "node:test";
import assert from "node:assert/strict";

import { fieldLabel, fieldValue } from "../../src/Dashboard/dashboard/format.js";

test("the reason for sending a battery reading is in Portuguese", () => {
    assert.equal(fieldLabel("reportReason"), "Motivo do envio");
    assert.equal(fieldValue("reportReason", "power_on"), "Ao ligar");
    assert.equal(fieldValue("reportReason", "low_battery"), "Bateria fraca");
});

test("charging is a yes or no", () => {
    assert.equal(fieldLabel("charging"), "A carregar");
    assert.equal(fieldValue("charging", true), "Sim");
});
