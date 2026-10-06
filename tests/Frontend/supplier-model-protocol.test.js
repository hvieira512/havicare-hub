import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { supplierProtocol } from "../../src/Dashboard/dashboard/domain.js";

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
