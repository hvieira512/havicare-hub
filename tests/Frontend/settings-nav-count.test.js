import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import {
    initSettingsShell,
    setSettingsNavCount,
} from "../../src/Dashboard/dashboard/settings/shell.js";

/**
 * A pastilha de contagem de um separador diz quantos são. A zero não diz nada que a lista
 * vazia já não diga, e a «Denylist» era a única a mostrá-la -- as outras escondiam-na por
 * acaso, por nunca chegarem a zero.
 */
function setUpNav() {
    document.body.innerHTML = "<span id=\"settingsDenylistCount\" class=\"d-none\"></span>";
    const element = document.getElementById("settingsDenylistCount");
    initSettingsShell({ els: { settingsDenylistCount: element } });
    return element;
}

test("uma contagem conhecida aparece", () => {
    const badge = setUpNav();

    setSettingsNavCount("Denylist", 3);

    assert.equal(badge.textContent, "3");
    assert.equal(badge.classList.contains("d-none"), false);
});

test("a zero, a pastilha esconde-se", () => {
    const badge = setUpNav();

    setSettingsNavCount("Denylist", 0);

    assert.equal(badge.classList.contains("d-none"), true);
});

test("desconhecida, esconde-se também", () => {
    const badge = setUpNav();

    setSettingsNavCount("Denylist", null);

    assert.equal(badge.classList.contains("d-none"), true);
});
