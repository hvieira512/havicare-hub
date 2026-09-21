import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { syncConfigSectionDirty } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * Uma acção sem parâmetros não tem valor para editar: tem um verbo e o estado do último
 * pedido. O cartão gastava quatro linhas a dizer isso -- um alerta a repetir o título, uma
 * legenda «sem parâmetros» e um botão de repor que não tinha o que repor -- e ficava com a
 * altura de um formulário para uma palavra.
 */
const FIND_DEVICE = {
    key: "findDeviceCommand",
    capabilityKey: "find_device",
    label: "Encontrar dispositivo",
    input: "action",
    fields: [],
    transient: true,
};

const POWER_OFF = {
    key: "powerOffCommand",
    capabilityKey: "power_off",
    label: "Desligar dispositivo",
    input: "action",
    fields: [],
    transient: true,
    confirm: "O relógio desliga-se e só volta a ligar no botão do próprio aparelho.",
};

const sectionOf = (entry) => parseFragment(renderConfigSection("wonlex-json", entry, null));

test("a acção não repete o título dentro de um alerta", () => {
    const section = sectionOf(FIND_DEVICE);

    assert.equal(section.querySelectorAll(".alert").length, 0);
    assert.doesNotMatch(section.textContent, /sem parâmetros/);
    // Um renderizador que não desenha nada caía no editor de JSON, porque a escolha do
    // campo era um `||` sobre o resultado em vez de uma pergunta ao descritor.
    assert.equal(section.querySelectorAll("textarea").length, 0);
});

test("uma acção sem campos não tem botão de repor", () => {
    const section = sectionOf(FIND_DEVICE);

    assert.equal(section.querySelectorAll("button[type=\"reset\"]").length, 0);
});

test("o botão de enviar fica na linha do título", () => {
    const section = sectionOf(FIND_DEVICE);
    const buttons = section.querySelectorAll("[data-action=\"saveConfig\"]");

    assert.equal(buttons.length, 1);
    const row = buttons[0].closest("div").parentElement;
    assert.ok(
        row.querySelector(".fw-semibold"),
        "o botão devia partilhar a linha com o nome da definição",
    );
});

test("uma acção destrutiva pinta-se de perigo, e continua assim depois de sincronizar", () => {
    const section = sectionOf(POWER_OFF);
    const button = section.querySelector("[data-action=\"saveConfig\"]");

    assert.ok(button.classList.contains("btn-outline-danger"));

    syncConfigSectionDirty(section);

    assert.ok(
        button.classList.contains("btn-outline-danger"),
        "o peso de uma acção destrutiva não pode ser apagado pelo estado do botão",
    );
    assert.ok(!button.classList.contains("btn-primary"));
});
