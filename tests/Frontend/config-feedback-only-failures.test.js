import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * A caixa de mensagem do cartão é para o que a pastilha não sabe dizer.
 *
 * A pastilha conta a história toda de um pedido e vai mudando com ela; uma caixa de sucesso
 * congela um instante e fica a contradizê-la. A falha é outra coisa: um pedido que nem chega
 * a criar comando não tem pastilha nenhuma, e sem esta caixa o clique morria em silêncio.
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

test("o cartão não mostra caixa de sucesso: quem conta isso é a pastilha", () => {
    const html = render({
        phase: "sent",
        feedback: { tone: "success", message: "Pedido enviado ao dispositivo." },
    });

    assert.ok(!html.includes("Pedido enviado ao dispositivo."));
    assert.ok(!/alert-success/.test(html), "não devia haver caixa verde nenhuma");
});

test("uma falha continua a ser dita, que é o que a pastilha não consegue", () => {
    const html = render({
        phase: "idle",
        feedback: { tone: "danger", message: "Falha ao enviar configuração" },
    });

    assert.ok(html.includes("Falha ao enviar configuração"));
    assert.ok(/alert-danger/.test(html));
});

test("sem mensagem nenhuma, não há caixa", () => {
    assert.ok(!/alert-dismissible/.test(render(null)));
});
