import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// Sem temporizadores reais: o módulo da sessão arma relógios de inatividade de minutos.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const { initializePasswordVisibility, resetPasswordVisibility } = await import(
    "../../src/Dashboard/dashboard/auth/session.js",
);

/**
 * O olho que mostra a palavra-passe. O que importa prender é fechar-se ao sair: o ecrã
 * seguinte é de outra pessoa.
 */
const mount = () => {
    document.body.innerHTML = `
        <input id="dashboardLoginPassword" type="password">
        <button type="button" data-password-toggle="dashboardLoginPassword"
                aria-pressed="false" aria-label="Mostrar a palavra-passe">
            <i class="fa-solid fa-eye"></i>
        </button>
    `;

    return {
        input: document.getElementById("dashboardLoginPassword"),
        button: document.querySelector("[data-password-toggle]"),
    };
};

test("carregar no olho mostra a palavra-passe e voltar a carregar esconde-a", () => {
    const { input, button } = mount();
    initializePasswordVisibility();

    button.click();
    assert.equal(input.getAttribute("type"), "text");

    button.click();
    assert.equal(input.getAttribute("type"), "password");
});

test("o ícone e a etiqueta dizem o que o próximo toque faz", () => {
    const { button } = mount();
    initializePasswordVisibility();

    assert.ok(button.querySelector("i").classList.contains("fa-eye"));

    button.click();
    assert.ok(button.querySelector("i").classList.contains("fa-eye-slash"));
    assert.equal(button.getAttribute("aria-label"), "Ocultar a palavra-passe");
    assert.equal(button.getAttribute("aria-pressed"), "true");

    button.click();
    assert.ok(button.querySelector("i").classList.contains("fa-eye"));
    assert.equal(button.getAttribute("aria-label"), "Mostrar a palavra-passe");
    assert.equal(button.getAttribute("aria-pressed"), "false");
});

test("voltar ao ecrã de entrada esconde outra vez a palavra-passe", () => {
    const { input, button } = mount();
    initializePasswordVisibility();

    button.click();
    assert.equal(input.getAttribute("type"), "text");

    resetPasswordVisibility();

    assert.equal(input.getAttribute("type"), "password");
    assert.equal(button.getAttribute("aria-pressed"), "false");
    assert.ok(button.querySelector("i").classList.contains("fa-eye"));
});

test("sem olho na página, ligar o comportamento não rebenta", () => {
    document.body.innerHTML = "";

    assert.doesNotThrow(() => initializePasswordVisibility());
    assert.doesNotThrow(() => resetPasswordVisibility());
});
