import test from "node:test";
import assert from "node:assert/strict";

const { supplierModelLabel, supplierProtocol } = await import("../../src/Dashboard/dashboard/domain.js");

/**
 * Seis dos vinte modelos da frota já trazem o fornecedor no nome comercial, como o MONIT:
 * juntar os dois em cru daria «MONIT MONIT MECS Pro».
 */
test("the supplier is not repeated when the commercial name already starts with it", () => {
    assert.equal(supplierModelLabel("MONIT", "MONIT MECS Pro"), "MONIT MECS Pro");
    assert.equal(supplierModelLabel("MOKO", "MOKOSmart MKGW3"), "MOKOSmart MKGW3");
    assert.equal(supplierModelLabel("MOKO", "MOKO W6B"), "MOKO W6B");
});

test("the supplier is prefixed when the commercial name lacks it", () => {
    assert.equal(supplierModelLabel("Wonlex", "HW20PRO"), "Wonlex HW20PRO");
    assert.equal(supplierModelLabel("Zayata", "M228"), "Zayata M228");
});

test("the comparison ignores case, which the catalog does not guarantee", () => {
    assert.equal(supplierModelLabel("Monit", "MONIT MECS Pro"), "MONIT MECS Pro");
});

test("with one of the two missing, what is left shows without stray spaces", () => {
    assert.equal(supplierModelLabel("", "M228"), "M228");
    assert.equal(supplierModelLabel("Zayata", ""), "Zayata");
    assert.equal(supplierModelLabel("", ""), "");
});

/**
 * Um fornecedor pode vender aparelhos de protocolos diferentes: os relógios Wonlex falam TCP
 * (`wonlex-json`) e a pulseira MF91 fala BLE (`veepoo-ble`).
 */
const MODELS = [
    { supplier: "Wonlex", internalModel: "HW20PRO", protocol: "wonlex-json" },
    { supplier: "Wonlex", internalModel: "MF91", protocol: "veepoo-ble" },
    { supplier: "MOKO", internalModel: "W6B", protocol: "moko-w6b" },
];

test("the model decides the protocol when the supplier sells more than one", () => {
    assert.equal(supplierProtocol("Wonlex", MODELS, "MF91"), "veepoo-ble");
    assert.equal(supplierProtocol("Wonlex", MODELS, "HW20PRO"), "wonlex-json");
});

test("without a model, the supplier's first one still applies", () => {
    assert.equal(supplierProtocol("Wonlex", MODELS), "wonlex-json");
});

test("a model unknown to the supplier makes up no protocol", () => {
    assert.equal(supplierProtocol("Wonlex", MODELS, "MF99"), "wonlex-json");
    assert.equal(supplierProtocol("Fornecedor Novo", MODELS, "X1"), "");
});
