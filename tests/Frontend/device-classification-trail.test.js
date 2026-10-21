import test from "node:test";
import assert from "node:assert/strict";

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
