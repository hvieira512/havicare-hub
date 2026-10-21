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

test("without a stored preference or a dark system, it opens light", () => {
    reset();

    assert.equal(preferredTheme(), LIGHT);
});

test("without a stored preference, the system's is followed", () => {
    reset();
    setPrefersDark(true);

    assert.equal(preferredTheme(), DARK);
});

test("the stored preference beats the system's", () => {
    reset();
    setPrefersDark(true);
    localStorage.setItem(THEME_STORAGE_KEY, LIGHT);

    assert.equal(preferredTheme(), LIGHT);
});

test("a corrupt stored value picks no theme", () => {
    reset();
    setPrefersDark(true);
    localStorage.setItem(THEME_STORAGE_KEY, "arco-iris");

    assert.equal(preferredTheme(), DARK, "o estragado cai para o sistema, e não para o claro");
});

test("applying the theme writes it on the root element", () => {
    reset();

    applyTheme(DARK);
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), DARK);

    applyTheme(LIGHT);
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
});

test("the theme is also written on the attribute SweetAlert reads", () => {
    reset();

    applyTheme(DARK);
    assert.equal(document.body.getAttribute("data-swal2-theme"), "bootstrap-5-dark");

    applyTheme(LIGHT);
    assert.equal(document.body.getAttribute("data-swal2-theme"), "bootstrap-5-light");
});

test("the button state says where it goes, not where it is", () => {
    reset();
    const button = mountButton();

    applyTheme(LIGHT);
    assert.equal(button.getAttribute("aria-pressed"), "false");

    applyTheme(DARK);
    assert.equal(button.getAttribute("aria-pressed"), "true");
});

test("pressing the button switches the theme and stores the choice", () => {
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

// Há um botão na entrada e outro na dashboard: nenhum deles é *o* botão.
test("every button on the page follows the applied theme", () => {
    reset();
    const buttons = mountTwoButtons();

    applyTheme(DARK);

    buttons.forEach((button) => {
        assert.equal(button.getAttribute("aria-pressed"), "true");
    });
});

test("pressing any of the buttons switches the theme", () => {
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

test("without a button on the page, applying the theme does not break", () => {
    reset();

    assert.doesNotThrow(() => initializeTheme());
    assert.equal(document.documentElement.getAttribute("data-bs-theme"), LIGHT);
});
