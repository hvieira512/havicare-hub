import test from "node:test";
import assert from "node:assert/strict";

import { setPrefersDark } from "./support/browser-env.js";

const { applyTheme, initializeTheme, preferredTheme, DARK, LIGHT } =
    await import("../../src/Dashboard/dashboard/theme.js");
const { THEME_STORAGE_KEY } = await import("../../src/Dashboard/dashboard/storage.js");

/**
 * O tema claro/escuro. O que se afirma aqui é o que o Bootstrap não faz por nós: qual o tema
 * escolhido, e o que o botão mostra depois de ser carregado.
 */

const themeButton = () =>
    "<button data-theme-toggle><i class=\"fa-solid fa-moon fa-fw\"></i></button>";

function mountButton() {
    document.body.innerHTML = themeButton();

    return document.querySelector("[data-theme-toggle]");
}

function mountTwoButtons() {
    document.body.innerHTML = themeButton() + themeButton();

    return [...document.querySelectorAll("[data-theme-toggle]")];
}

function reset() {
    localStorage.clear();
    setPrefersDark(false);
    document.documentElement.removeAttribute("data-bs-theme");
    document.body.removeAttribute("data-swal2-theme");
    document.body.innerHTML = "";
}

test("sem preferência guardada nem sistema escuro, abre no claro", () => {
    reset();

    assert.equal(preferredTheme(), LIGHT);
});

test("sem preferência guardada, segue-se a do sistema", () => {
    reset();
    setPrefersDark(true);

    assert.equal(preferredTheme(), DARK);
});

test("a preferência guardada ganha à do sistema", () => {
    reset();
    setPrefersDark(true);
    localStorage.setItem(THEME_STORAGE_KEY, LIGHT);

    assert.equal(preferredTheme(), LIGHT);
});

test("um valor estragado no armazenamento não escolhe tema nenhum", () => {
    reset();
    setPrefersDark(true);
    localStorage.setItem(THEME_STORAGE_KEY, "arco-iris");

    assert.equal(preferredTheme(), DARK, "o estragado cai para o sistema, e não para o claro");
});

test("aplicar o tema escreve-o no elemento raiz", () => {
    reset();

    applyTheme(DARK);
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), DARK);

    applyTheme(LIGHT);
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
});

test("o tema também é escrito no atributo que o SweetAlert lê", () => {
    reset();

    applyTheme(DARK);
    assert.equal(document.body.getAttribute("data-swal2-theme"), "bootstrap-5-dark");

    applyTheme(LIGHT);
    assert.equal(document.body.getAttribute("data-swal2-theme"), "bootstrap-5-light");
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

// São dois desde que a entrada ganhou o seu: nenhum deles é *o* botão.
test("todos os botões da página acompanham o tema aplicado", () => {
    reset();
    const buttons = mountTwoButtons();

    applyTheme(DARK);

    buttons.forEach((button) => {
        assert.ok(button.querySelector("i").classList.contains("fa-sun"));
        assert.equal(button.getAttribute("aria-pressed"), "true");
    });
});

test("carregar em qualquer um dos botões troca o tema", () => {
    reset();
    const [navbar, login] = mountTwoButtons();
    initializeTheme();

    login.click();
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), DARK);
    assert.equal(navbar.getAttribute("aria-pressed"), "true");

    navbar.click();
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
    assert.equal(login.getAttribute("aria-pressed"), "false");
});

test("sem botão na página, aplicar o tema não rebenta", () => {
    reset();

    assert.doesNotThrow(() => initializeTheme());
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
});
