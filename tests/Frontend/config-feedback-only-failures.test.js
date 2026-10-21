import assert from "node:assert/strict";
import test from "node:test";

import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * A caixa de mensagem é só para falhas: um sucesso congelado contradiz a pastilha, e um pedido
 * que nem cria comando não tem pastilha nenhuma.
 */
const ACTION = {
    key: "find_device",
    capabilityKey: "find_device",
    command: "config:find_device",
    label: "Encontrar dispositivo",
    input: "toggle",
    fields: ["enabled"],
    transient: true,
    requestOnly: true,
    actions: { on: "Fazer vibrar", off: "Parar" },
};

const render = (uiState) =>
    renderConfigSection("veepoo-ble", ACTION, null, {}, false, uiState);

test("the card shows no success box: the badge tells that", () => {
    const html = render({
        phase: "sent",
        feedback: { tone: "success", message: "Pedido enviado ao dispositivo." },
    });

    assert.ok(!html.includes("Pedido enviado ao dispositivo."));
    assert.ok(!/alert-success/.test(html), "não devia haver caixa verde nenhuma");
});

test("a failure is still said, which the badge cannot do", () => {
    const html = render({
        phase: "idle",
        feedback: { tone: "danger", message: "Falha ao enviar configuração" },
    });

    assert.ok(html.includes("Falha ao enviar configuração"));
    assert.ok(/alert-danger/.test(html));
});

test("without any message there is no box", () => {
    assert.ok(!/alert-dismissible/.test(render(null)));
});
