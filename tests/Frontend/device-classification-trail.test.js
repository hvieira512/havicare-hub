import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { classificationTrailHtml } =
    await import("../../src/Dashboard/dashboard/devices/classification-ui.js");

/**
 * A classificação de um aparelho escolhido em pastilhas ligadas; a que está aberta não repete o
 * valor, que está marcado na grelha por baixo.
 */

const QUESTIONS = [
    { key: "type", label: "Tipo" },
    { key: "model", label: "Modelo" },
    { key: "owner", label: "Licença" },
];

const VALUES = { type: "Relógio", model: "4P Touch Y6M", owner: "besenior.havicare · 24" };

const trail = (openKey = "", known = true) =>
    parseFragment(classificationTrailHtml({
        questions: QUESTIONS,
        values: VALUES,
        openKey,
        known,
    }));

test("são três pastilhas, e cada uma abre a sua escolha", () => {
    const buttons = [...trail().querySelectorAll("[data-wizard-reopen]")];

    assert.deepEqual(
        buttons.map((button) => button.dataset.wizardReopen),
        ["type", "model", "owner"],
    );
});

test("cada pastilha diz a etiqueta e o valor", () => {
    const text = trail().textContent.replace(/\s+/g, " ");

    assert.match(text, /Tipo Relógio/);
    assert.match(text, /Modelo 4P Touch Y6M/);
    assert.match(text, /Licença besenior\.havicare · 24/);
});

test("a pastilha que está aberta não repete o valor: a escolha está por baixo", () => {
    const open = trail("model");

    assert.doesNotMatch(open.textContent, /4P Touch Y6M/);
    assert.match(open.textContent.replace(/\s+/g, " "), /Modelo/);
});

test("enquanto o dispositivo não chegou não se afirma classificação nenhuma", () => {
    const loading = trail("", false);

    assert.doesNotMatch(loading.textContent, /Relógio/);
    assert.doesNotMatch(loading.textContent, /besenior/);
});

/**
 * Deitadas há uma seta para a direita e empilhadas uma para baixo: nunca as duas, e nunca a
 * apontar para o vazio quando a fila quebra.
 */
test("entre duas pastilhas há uma seta para cada arranjo, e nenhuma antes da primeira", () => {
    const root = trail();
    const setas = [...root.querySelectorAll("i.fa-caret-right, i.fa-caret-down")];

    assert.equal(setas.filter((i) => i.classList.contains("fa-caret-right")).length, 2);
    assert.equal(setas.filter((i) => i.classList.contains("fa-caret-down")).length, 2);
    assert.equal(root.firstElementChild?.dataset.wizardReopen, "type");
});

/** Um valor comprido corta-se com reticências em vez de empurrar a pastilha para fora. */
test("o valor de qualquer um dos três se corta, e não só o do tipo", () => {
    const root = trail();

    for (const button of root.querySelectorAll("[data-wizard-reopen]")) {
        const value = button.lastElementChild;
        assert.ok(
            value.classList.contains("text-truncate"),
            `o valor de «${button.dataset.wizardReopen}» não se corta`,
        );
    }
});
