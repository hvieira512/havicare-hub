import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { applyTheme, initializeTheme, preferredTheme, DARK, LIGHT } =
    await import("../../src/Dashboard/dashboard/theme.js");
const { THEME_STORAGE_KEY } = await import("../../src/Dashboard/dashboard/storage.js");

/**
 * O tema claro/escuro. O que se afirma aqui é o que o Bootstrap não faz por nós: qual o tema
 * escolhido, e o que o botão mostra depois de ser carregado.
 */

function mountButton() {
    document.body.innerHTML =
        "<button id=\"dashboardThemeBtn\"><i class=\"fa-solid fa-moon fa-fw\"></i></button>";

    return document.getElementById("dashboardThemeBtn");
}

function reset() {
    localStorage.clear();
    document.documentElement.removeAttribute("data-bs-theme");
    document.body.innerHTML = "";
}

test("sem preferência guardada nem sistema escuro, abre no claro", () => {
    reset();

    assert.equal(preferredTheme(), LIGHT);
});

test("a preferência guardada ganha à do sistema", () => {
    reset();
    localStorage.setItem(THEME_STORAGE_KEY, DARK);

    assert.equal(preferredTheme(), DARK);
});

test("um valor estragado no armazenamento não escolhe tema nenhum", () => {
    reset();
    localStorage.setItem(THEME_STORAGE_KEY, "arco-iris");

    assert.equal(preferredTheme(), LIGHT);
});

test("aplicar o tema escreve-o no elemento raiz", () => {
    reset();

    applyTheme(DARK);
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), DARK);

    applyTheme(LIGHT);
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
});

test("o ícone do botão diz para onde se vai, não onde se está", () => {
    reset();
    const button = mountButton();

    applyTheme(LIGHT);
    assert.ok(button.querySelector("i").classList.contains("fa-moon"), "no claro vai-se ao escuro");
    assert.equal(button.getAttribute("aria-pressed"), "false");

    applyTheme(DARK);
    assert.ok(button.querySelector("i").classList.contains("fa-sun"), "no escuro vai-se ao claro");
    assert.ok(!button.querySelector("i").classList.contains("fa-moon"));
    assert.equal(button.getAttribute("aria-pressed"), "true");
});

test("o botão mantém a largura ao trocar de ícone", () => {
    reset();
    const button = mountButton();

    applyTheme(LIGHT);
    applyTheme(DARK);

    // O `fa-fw` é o que garante que um controlo não muda de tamanho por ter sido usado.
    assert.ok(button.querySelector("i").classList.contains("fa-fw"));
});

test("carregar no botão troca o tema e guarda a escolha", () => {
    reset();
    const button = mountButton();
    initializeTheme();

    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);

    button.click();
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), DARK);
    assert.equal(localStorage.getItem(THEME_STORAGE_KEY), DARK);

    button.click();
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
    assert.equal(localStorage.getItem(THEME_STORAGE_KEY), LIGHT);
});

test("sem botão na página, aplicar o tema não rebenta", () => {
    reset();

    assert.doesNotThrow(() => initializeTheme());
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
});
