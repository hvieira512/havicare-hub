import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { classificationRowsHtml } =
    await import("../../src/Dashboard/dashboard/devices/classification-ui.js");

/**
 * A classificação de um aparelho já escolhido — tipo, modelo e licença — não é uma trilha de
 * assistente: as três respostas já existem. Em pastilhas quebravam a 342 px e deixavam uma
 * seta a apontar para o vazio. Passam a três linhas de etiqueta e valor, cada uma a abrir a
 * sua escolha.
 */

const QUESTIONS = [
    { key: "type", label: "Tipo" },
    { key: "model", label: "Modelo" },
    { key: "owner", label: "Licença" },
];

const VALUES = { type: "Relógio", model: "4P Touch Y6M", owner: "besenior.havicare · 24" };

const rows = (openKey = "", known = true) =>
    parseFragment(classificationRowsHtml({
        questions: QUESTIONS,
        values: VALUES,
        openKey,
        known,
    }));

test("são três linhas, e cada uma abre a sua escolha", () => {
    const buttons = [...rows().querySelectorAll("[data-wizard-reopen]")];

    assert.deepEqual(
        buttons.map((button) => button.dataset.wizardReopen),
        ["type", "model", "owner"],
    );
});

test("cada linha diz a etiqueta e o valor", () => {
    const text = rows().textContent.replace(/\s+/g, " ");

    assert.match(text, /Tipo Relógio/);
    assert.match(text, /Modelo 4P Touch Y6M/);
    assert.match(text, /Licença besenior\.havicare · 24/);
});

test("a linha que está aberta não repete o valor: a escolha está por baixo", () => {
    const open = rows("model");

    assert.doesNotMatch(open.textContent, /4P Touch Y6M/);
    assert.match(open.textContent.replace(/\s+/g, " "), /Modelo/);
});

test("enquanto o dispositivo não chegou não se afirma classificação nenhuma", () => {
    const loading = rows("", false);

    assert.doesNotMatch(loading.textContent, /Relógio/);
    assert.doesNotMatch(loading.textContent, /besenior/);
});
