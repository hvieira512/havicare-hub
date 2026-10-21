import test from "node:test";
import assert from "node:assert/strict";

import { fieldLabel, fieldValue } from "../../src/Dashboard/dashboard/format.js";

/**
 * O `batteryType` do Wonlex não é o tipo da bateria: a spec declara-o como o motivo do envio
 * -- «Data type: 0 (on); 1, Off; 2. Report on time; 3, Low power».
 */
test("batteryType says the reason for sending, not a type", () => {
    assert.equal(fieldLabel("batteryType"), "Motivo do envio");
});

/** O mesmo campo chega em duas formas: o bit dos relógios e a enumeração do dispensador. */
test("the charge state does not come out as a code either", () => {
    assert.equal(fieldValue("chargingState", 0), "Não está a carregar");
    assert.equal(fieldValue("chargingState", 1), "A carregar");
    assert.equal(fieldValue("chargingState", "charging"), "A carregar");
    assert.equal(fieldValue("chargingState", "full"), "Carregada");
    assert.equal(fieldValue("chargingState", "absent"), "Sem bateria");
});

/** Um valor que a tabela não conheça passa intacto, e não vira uma palavra inventada. */
test("an unknown reason is not forced into a translation", () => {
    assert.equal(fieldValue("batteryType", 9), "9");
});
