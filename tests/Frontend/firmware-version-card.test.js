import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { requestCardShell } from "../../src/Dashboard/dashboard/components/cards/request.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

state.capabilityCatalogByType.pill_dispenser = [
    { key: "firmware_version", label: "Versão do firmware" },
];
state.selectedDetail = { model: { deviceType: "pill_dispenser" } };

const firmwareCard = (version) => requestCardShell(
    { feature: "firmware_version", requestable: false },
    false,
    [{ type: "firmware_version", occurredAt: "2026-09-24T08:13:26Z", data: { version } }],
);

/**
 * O cartão da versão do firmware mostra a versão, e não o nome da categoria.
 *
 * Sem renderizador próprio caía no genérico, que põe a etiqueta da capacidade no lugar do
 * valor: o cartão dizia «Versão do firmware» por cima e «Versão do firmware» por baixo.
 */
test("o valor do cartão é a versão", () => {
    assert.equal(
        uplinkCardContent("firmware_version", { version: "0x0502" }).value,
        "0x0502",
    );
});

test("a versão de um relógio, que é texto livre, sai na mesma", () => {
    assert.equal(
        uplinkCardContent("firmware_version", { version: "W6B_V1.3.7" }).value,
        "W6B_V1.3.7",
    );
});

test("sem versão não se inventa nenhuma", () => {
    assert.equal(uplinkCardContent("firmware_version", {}).value, "—");
});

// O cartão desenhado, e não só o renderizador: a versão estava a ser lida e deitada fora
// antes de chegar ao ecrã.
test("o cartão desenhado mostra a versão que chegou na telemetria", () => {
    assert.match(firmwareCard("0x0502"), /0x0502/);
});

test("o cartão sem leitura nenhuma não mostra versão", () => {
    const html = requestCardShell(
        { feature: "firmware_version", requestable: false },
        false,
        [],
    );

    assert.doesNotMatch(html, /0x/);
});
