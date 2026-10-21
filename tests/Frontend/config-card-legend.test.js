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
    label: "Frequência cardíaca contínua",
    input: "toggle",
    fields: ["enabled"],
    help: "Mede ao longo do dia, um valor por minuto.",
};

const render = (entry) => renderConfigSection("veepoo-ble", entry, null);

test("the setting's caption appears on the card", () => {
    const html = render(ENTRY);

    assert.ok(
        html.includes("Mede ao longo do dia"),
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
