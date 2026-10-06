import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { commandLabel } from "../../src/Dashboard/dashboard/format.js";

/**
 * As etiquetas do catálogo de comandos são inglesas por desenho e a tradução faz-se aqui: uma
 * etiqueta nova sem entrada no mapa chega ao ecrã em inglês.
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
