import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configActionPayload } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * O «Encontrar dispositivo» vibra no instante e desiste ao fim de um minuto: é uma acção com
 * dois verbos, e não um interruptor que se guarda.
 */
const ACTION = {
    key: "find_device",
    capabilityKey: "find_device",
    command: "config:find_device",
    label: "Encontrar dispositivo",
    input: "toggle",
    fields: ["enabled"],
    transient: true,
    help: "Faz a pulseira vibrar. Pára sozinha ao fim de cerca de um minuto.",
    actions: { on: "Fazer vibrar", off: "Parar" },
};

const root = (entry) => parseFragment(renderConfigSection("veepoo-ble", entry, null)).firstElementChild;
const buttons = (el) => [...el.querySelectorAll("[data-action=\"saveConfig\"]")]
    .map((b) => ({ texto: b.textContent.trim(), valor: b.dataset.actionValue }));

test("os dois verbos aparecem como botões", () => {
    const actionButtons = buttons(root(ACTION));

    assert.deepEqual(actionButtons, [
        { texto: "Fazer vibrar", valor: "on" },
        { texto: "Parar", valor: "off" },
    ]);
});

test("não há interruptor a fingir que a acção tem estado guardado", () => {
    assert.equal(root(ACTION).querySelector("input[type=\"checkbox\"]"), null);
});

test("sem verbos declarados, o cartão não muda", () => {
    const withoutVerbs = { ...ACTION };
    delete withoutVerbs.actions;
    const el = root(withoutVerbs);

    assert.ok(el.querySelector("input[type=\"checkbox\"]"), "devia continuar a ter o interruptor");
    assert.deepEqual(buttons(el).map((b) => b.valor), [undefined]);
});

/** Um botão «Parar» não tem formulário: o valor que envia está no próprio botão. */
test("cada verbo envia o seu valor", () => {
    const section = parseFragment(
        "<section data-config-section data-config-action-field=\"enabled\"></section>",
    ).firstElementChild;

    assert.deepEqual(configActionPayload(section, "on"), { enabled: true });
    assert.deepEqual(configActionPayload(section, "off"), { enabled: false });
});

test("sem verbo, o valor vem do formulário como sempre", () => {
    const section = parseFragment("<section data-config-section></section>").firstElementChild;

    assert.equal(configActionPayload(section, ""), null);
});
