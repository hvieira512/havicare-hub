import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { fieldLabel, fieldValue } from "../../src/Dashboard/dashboard/format.js";

/**
 * O `batteryType` do Wonlex não é o tipo da bateria: a spec declara-o como o motivo do envio
 * -- «Data type: 0 (on); 1, Off; 2. Report on time; 3, Low power».
 */
test("o batteryType diz o motivo do envio, e não um tipo", () => {
    assert.equal(fieldLabel("batteryType"), "Motivo do envio");
});

test("e os quatro motivos saem por palavras", () => {
    assert.equal(fieldValue("batteryType", 0), "Ao ligar");
    assert.equal(fieldValue("batteryType", 1), "Ao desligar");
    assert.equal(fieldValue("batteryType", 2), "Envio periódico");
    assert.equal(fieldValue("batteryType", 3), "Bateria fraca");
});

/** O mesmo campo chega em duas formas: o bit dos relógios e a enumeração do dispensador. */
test("o estado de carga também não sai em código", () => {
    assert.equal(fieldValue("chargingState", 0), "Não está a carregar");
    assert.equal(fieldValue("chargingState", 1), "A carregar");
    assert.equal(fieldValue("chargingState", "charging"), "A carregar");
    assert.equal(fieldValue("chargingState", "full"), "Carregada");
    assert.equal(fieldValue("chargingState", "absent"), "Sem bateria");
});

/** Um valor que a tabela não conheça passa intacto, e não vira uma palavra inventada. */
test("um motivo desconhecido não se traduz à força", () => {
    assert.equal(fieldValue("batteryType", 9), "9");
});
