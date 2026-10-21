import test from "node:test";
import assert from "node:assert/strict";

import {
    capabilityFacts,
    showsInCatalog,
} from "../../src/Dashboard/dashboard/settings/capabilities.js";

/** As acções fazem parte do que o tipo de dispositivo tem, e o catálogo mostra-as. */
const capability = (flags) => ({
    key: "x",
    label: "X",
    isTelemetry: false,
    isConfigurable: false,
    isRequestable: false,
    isEvent: false,
    ...flags,
});

test("an action appears in the catalog", () => {
    assert.equal(showsInCatalog(capability({ isRequestable: true })), true);
});

test("telemetry, configuration and event still appear", () => {
    assert.equal(showsInCatalog(capability({ isTelemetry: true })), true);
    assert.equal(showsInCatalog(capability({ isConfigurable: true })), true);
    assert.equal(showsInCatalog(capability({ isEvent: true })), true);
});

test("a capability with no flag stays out", () => {
    assert.equal(showsInCatalog(capability({})), false);
});

/**
 * «Solicitável» diz que se pode pedir uma leitura, e uma acção não devolve leitura nenhuma:
 * mandar desligar o relógio não é o mesmo gesto que pedir-lhe a frequência cardíaca.
 */
test("an action says Ação and an on-demand measurement says Solicitável", () => {
    assert.deepEqual(capabilityFacts(capability({ isRequestable: true })), ["Ação"]);
    assert.deepEqual(
        capabilityFacts(capability({ isTelemetry: true, isRequestable: true })),
        ["Solicitável"],
    );
    assert.deepEqual(
        capabilityFacts(capability({ isConfigurable: true, isRequestable: true })),
        ["Configurável", "Solicitável"],
    );
});
