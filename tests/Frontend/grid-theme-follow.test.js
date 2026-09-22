import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { isDarkTheme, applyTheme, DARK, LIGHT } =
    await import("../../src/Dashboard/dashboard/theme.js");

/**
 * Quem desenha fora do CSS não herda o tema e tem de o perguntar. O AG Grid é o caso: recebe
 * o tema como opção, e a grelha dos Utilizadores API é construída uma vez por sessão.
 */
beforeEach(() => {
    document.body.innerHTML = "";
    applyTheme(LIGHT);
});

test("o tema que está no ecrã lê-se do elemento raiz", () => {
    applyTheme(DARK);
    assert.equal(isDarkTheme(), true);

    applyTheme(LIGHT);
    assert.equal(isDarkTheme(), false);
});

test("o botão continua a alternar a partir do que está no ecrã", () => {
    document.body.innerHTML = "<button id=\"dashboardThemeBtn\"><i class=\"fa-solid fa-moon\"></i></button>";
    applyTheme(DARK);

    assert.equal(isDarkTheme(), true, "o alternar do botão lê daqui, e não do armazenamento");
});
