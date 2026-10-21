import assert from "node:assert/strict";
import test from "node:test";

import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * O cartão diz o que a definição faz, e não como se chama no protocolo: esse vocabulário serve
 * os registos e o diagnóstico, não quem gere dispositivos.
 */
const ENTRY = {
    key: "heart_rate_continuous",
    capabilityKey: "heart_rate_continuous",
    command: "config:heart_rate_continuous",
    input: "toggle",
    fields: ["enabled"],
};

const render = (entry) => renderConfigSection("veepoo-ble", entry, null);

test("the dictionary's caption for the setting appears on the card", () => {
    const html = render(ENTRY);

    assert.ok(
        html.includes("Um valor por minuto."),
        "o cartão devia explicar o que a definição faz",
    );
});

test("the protocol's vocabulary appears nowhere on the card", () => {
    const html = render(ENTRY);

    assert.ok(
        !html.includes("config:heart_rate_continuous"),
        "o nome do comando é para os registos, não para quem gere dispositivos",
    );
    assert.ok(!html.includes("Toggle"), "nem o tipo de campo interno");
});
