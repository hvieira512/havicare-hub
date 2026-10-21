import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/** Uma acção sem parâmetros tem um verbo e o estado do último pedido, e nada para editar. */
// O `command` entra porque as vinte e uma acções do catálogo o têm: sem ele o cartão toma-a
// por uma definição que o hub aplica sozinho, e o botão diz «Guardar» em vez de «Enviar».
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

test("a acção não repete o título dentro de um alerta", () => {
    const section = sectionOf(FIND_DEVICE);

    assert.equal(section.querySelectorAll(".alert").length, 0);
    assert.doesNotMatch(section.textContent, /sem parâmetros/);
    // O campo escolhe-se perguntando ao descritor, e não com um `||` sobre o resultado: assim
    // um renderizador que não desenha nada não cai no editor de JSON.
    assert.equal(section.querySelectorAll("textarea").length, 0);
});

test("uma acção sem campos não tem botão de repor", () => {
    const section = sectionOf(FIND_DEVICE);

    assert.equal(section.querySelectorAll("button[type=\"reset\"]").length, 0);
});

/** «Reposição de fábrica» num botão não diz o que o clique faz: manda o verbo declarado. */
test("um rótulo que é um nome leva o verbo declarado na definição", () => {
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

test("sem verbo declarado, o botão diz Enviar e não repete o título", () => {
    const button = sectionOf(FIND_DEVICE).querySelector("[data-action=\"saveConfig\"]");

    assert.equal(button.textContent.trim(), "Enviar");
});
