import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { deliveryStatusFromCommand } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * Uma acção também tem estado, e o cartão tem de o dizer.
 *
 * Um pedido passa por em fila, à espera, confirmado ou falhado, e isso aparece na lista de
 * pedidos do dispositivo. No cartão que o disparou não aparecia nada: carregava-se em «Fazer
 * vibrar» e o ecrã ficava igual, sem dizer se a ordem tinha sequer saído do hub.
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

test("o estado de um comando traduz-se no mesmo vocabulário das configurações", () => {
    assert.equal(deliveryStatusFromCommand("queued"), "pending_delivery");
    assert.equal(deliveryStatusFromCommand("waiting"), "awaiting_ack");
    assert.equal(deliveryStatusFromCommand("acked"), "confirmed");
    assert.equal(deliveryStatusFromCommand("failed"), "failed");
    assert.equal(deliveryStatusFromCommand("dropped"), "failed");
});

/** Um comando confirmado por um aparelho que não confirma execução não mente que confirmou. */
test("um aparelho que só acusa a receção não diz confirmado", () => {
    assert.equal(deliveryStatusFromCommand("acked", "ack_only"), "confirmation_unavailable");
});

test("o cartão da acção mostra o estado do último pedido", () => {
    const html = renderConfigSection(
        "veepoo-ble",
        ACTION,
        null,
        {},
        false,
        null,
        null,
        { status: "awaiting_ack" },
    );

    assert.ok(html.includes("badge"), "devia haver uma pastilha de estado");
    assert.ok(
        /aguardar|espera/i.test(html),
        "a pastilha devia dizer que se está à espera do dispositivo",
    );
});

/** Sem pedido nenhum ainda, não há estado que inventar. */
test("uma acção que nunca foi pedida não mostra pastilha", () => {
    const html = renderConfigSection("veepoo-ble", ACTION, null);

    assert.ok(!/badge/.test(html), "não devia haver pastilha antes do primeiro pedido");
});
