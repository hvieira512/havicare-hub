import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { supplierModelLabel, supplierProtocol } = await import("../../src/Dashboard/dashboard/domain.js");

/**
 * Seis dos vinte modelos da frota já trazem o fornecedor no nome comercial, como o MONIT:
 * juntar os dois em cru daria «MONIT MONIT MECS Pro».
 */
test("o fornecedor não se repete quando o nome comercial já começa por ele", () => {
    assert.equal(supplierModelLabel("MONIT", "MONIT MECS Pro"), "MONIT MECS Pro");
    assert.equal(supplierModelLabel("MOKO", "MOKOSmart MKGW3"), "MOKOSmart MKGW3");
    assert.equal(supplierModelLabel("MOKO", "MOKO W6B"), "MOKO W6B");
});

test("o fornecedor entra à frente quando o nome comercial não o traz", () => {
    assert.equal(supplierModelLabel("Wonlex", "HW20PRO"), "Wonlex HW20PRO");
    assert.equal(supplierModelLabel("Zayata", "M228"), "Zayata M228");
});

test("a comparação ignora maiúsculas, que o catálogo não garante", () => {
    assert.equal(supplierModelLabel("Monit", "MONIT MECS Pro"), "MONIT MECS Pro");
});

test("com um dos dois em falta sobra o que há, sem espaços soltos", () => {
    assert.equal(supplierModelLabel("", "M228"), "M228");
    assert.equal(supplierModelLabel("Zayata", ""), "Zayata");
    assert.equal(supplierModelLabel("", ""), "");
});

/**
 * Um fornecedor pode vender aparelhos de protocolos diferentes: os relógios Wonlex falam TCP
 * (`wonlex-json`) e a pulseira MF91 fala BLE (`veepoo-ble`).
 */
const MODELS = [
    { supplier: "Wonlex", internal_model: "HW20PRO", protocol: "wonlex-json" },
    { supplier: "Wonlex", internal_model: "MF91", protocol: "veepoo-ble" },
    { supplier: "MOKO", internal_model: "W6B", protocol: "moko-w6b" },
];

test("o modelo decide o protocolo quando o fornecedor vende mais do que um", () => {
    assert.equal(supplierProtocol("Wonlex", MODELS, "MF91"), "veepoo-ble");
    assert.equal(supplierProtocol("Wonlex", MODELS, "HW20PRO"), "wonlex-json");
});

test("sem modelo, continua a valer o primeiro do fornecedor", () => {
    assert.equal(supplierProtocol("Wonlex", MODELS), "wonlex-json");
});

test("um modelo desconhecido do fornecedor não inventa protocolo nenhum", () => {
    assert.equal(supplierProtocol("Wonlex", MODELS, "MF99"), "wonlex-json");
    assert.equal(supplierProtocol("Fornecedor Novo", MODELS, "X1"), "");
});
