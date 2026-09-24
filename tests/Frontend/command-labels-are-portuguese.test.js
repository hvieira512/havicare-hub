import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { commandLabel } from "../../src/Dashboard/dashboard/format.js";

/**
 * Nada aparece em inglês na dashboard.
 *
 * As etiquetas do catálogo de comandos são inglesas por desenho -- o catálogo é código -- e a
 * tradução faz-se aqui, na fronteira. Uma etiqueta nova sem entrada no mapa chegava ao ecrã
 * como veio: o «Refresh telemetry» apareceu assim na lista de pedidos.
 */
test("a etiqueta do recarregar chega traduzida", () => {
    assert.equal(
        commandLabel({ label: "Refresh telemetry" }),
        "Atualizar telemetria",
    );
});

test("as etiquetas que já existiam continuam traduzidas", () => {
    assert.equal(commandLabel({ label: "Device status" }), "Estado do dispositivo");
    assert.equal(commandLabel({ label: "Stored configuration" }), "Configuração guardada");
});
