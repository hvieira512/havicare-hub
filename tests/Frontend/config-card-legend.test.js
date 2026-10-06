import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
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

test("a legenda da definição aparece no cartão", () => {
    const html = render(ENTRY);

    assert.ok(
        html.includes("Mede ao longo do dia"),
        "o cartão devia explicar o que a definição faz",
    );
});

test("o vocabulário do protocolo não aparece em lado nenhum do cartão", () => {
    const html = render(ENTRY);

    assert.ok(
        !html.includes("config:heart_rate_continuous"),
        "o nome do comando é para os registos, não para quem gere dispositivos",
    );
    assert.ok(!html.includes("Toggle"), "nem o tipo de campo interno");
});

test("uma definição sem legenda não passa a mostrar o comando", () => {
    const semLegenda = { ...ENTRY };
    delete semLegenda.help;
    const html = render(semLegenda);

    assert.ok(html.includes("Frequência cardíaca contínua"));
    assert.ok(!html.includes("config:heart_rate_continuous"));
});
