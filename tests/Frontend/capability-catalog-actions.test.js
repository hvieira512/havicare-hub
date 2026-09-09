import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import {
    capabilityFacts,
    showsInCatalog,
} from "../../src/Dashboard/dashboard/settings/capabilities.js";

/**
 * O catálogo mostra o que o tipo de dispositivo tem, e as acções fazem parte disso.
 *
 * O filtro pedia telemetria, configuração ou evento, e uma acção não é nenhuma das três: sete
 * capacidades do relógio -- desligar, reiniciar, repor, encontrar, ligar, enviar mensagem e o
 * número de monitorização -- desapareciam do ecrã. O cabeçalho continuava a contá-las, e
 * anunciava 69 capacidades numa página que mostrava 62.
 */
const capability = (flags) => ({
    key: "x",
    label: "X",
    isTelemetry: false,
    isConfigurable: false,
    isRequestable: false,
    isEvent: false,
    ...flags,
});

test("uma acção aparece no catálogo", () => {
    assert.equal(showsInCatalog(capability({ isRequestable: true })), true);
});

test("telemetria, configuração e evento continuam a aparecer", () => {
    assert.equal(showsInCatalog(capability({ isTelemetry: true })), true);
    assert.equal(showsInCatalog(capability({ isConfigurable: true })), true);
    assert.equal(showsInCatalog(capability({ isEvent: true })), true);
});

test("uma capacidade sem marca nenhuma continua de fora", () => {
    assert.equal(showsInCatalog(capability({})), false);
});

/**
 * «Solicitável» diz que se pode pedir uma leitura, e uma acção não devolve leitura nenhuma:
 * mandar desligar o relógio não é o mesmo gesto que pedir-lhe a frequência cardíaca.
 */
test("uma acção diz Ação e uma medição a pedido diz Solicitável", () => {
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
