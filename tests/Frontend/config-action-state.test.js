import assert from "node:assert/strict";
import test from "node:test";

import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { deliveryStatusFromCommand } from "../../src/Dashboard/dashboard/devices/config/delivery.js";
import { parseFragment } from "./support/dom.js";

/** O cartão que disparou um pedido diz se ele está em fila, à espera, confirmado ou falhado. */
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
    const badge = parseFragment(renderConfigSection(
        "veepoo-ble",
        ACTION,
        null,
        {},
        false,
        null,
        null,
        { status: "awaiting_ack" },
    )).querySelector(".state-badge");

    assert.ok(badge, "devia haver uma pastilha de estado");
    assert.match(badge.textContent, /aguardar|espera/i);
});

/** Sem pedido nenhum ainda, não há estado que inventar. */
test("uma acção que nunca foi pedida não mostra pastilha", () => {
    const section = parseFragment(renderConfigSection("veepoo-ble", ACTION, null));

    assert.equal(section.querySelector(".state-badge"), null);
});
