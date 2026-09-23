import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

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
