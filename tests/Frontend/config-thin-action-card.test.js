import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

// Sem `command` o cartão toma a acção por uma definição que o hub aplica sozinho.
const FIND_DEVICE = {
    key: "findDeviceCommand",
    capabilityKey: "find_device",
    command: "find",
    label: "Encontrar dispositivo",
    input: "action",
    fields: [],
    transient: true,
};

const sectionOf = (entry) => parseFragment(renderConfigSection("wonlex-json", entry, null));

test("the action does not repeat the title inside an alert", () => {
    const section = sectionOf(FIND_DEVICE);

    assert.equal(section.querySelectorAll(".alert").length, 0);
    assert.doesNotMatch(section.textContent, /sem parâmetros/);
    // O campo escolhe-se perguntando ao descritor, e não com um `||` sobre o resultado: assim
    // um renderizador que não desenha nada não cai no editor de JSON.
    assert.equal(section.querySelectorAll("textarea").length, 0);
});

test("an action without fields has no reset button", () => {
    const section = sectionOf(FIND_DEVICE);

    assert.equal(section.querySelectorAll("button[type=\"reset\"]").length, 0);
});

/** «Reposição de fábrica» num botão não diz o que o clique faz: manda o verbo declarado. */
test("a label that is a name takes the verb declared in the setting", () => {
    const section = sectionOf({
        key: "factoryResetCommand",
        capabilityKey: "reset_device",
        label: "Reposição de fábrica",
        verb: "Repor de fábrica",
        input: "action",
        fields: [],
        transient: true,
        confirm: "Repõe o relógio ao estado de fábrica.",
    });

    const button = section.querySelector("[data-action=\"saveConfig\"]");
    assert.equal(button.textContent.trim(), "Repor de fábrica");
    assert.match(section.querySelector(".fw-semibold").textContent, /Reposição de fábrica/);
});

test("without a declared verb the button says Enviar and does not repeat the title", () => {
    const button = sectionOf(FIND_DEVICE).querySelector("[data-action=\"saveConfig\"]");

    assert.equal(button.textContent.trim(), "Enviar");
});
