import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { activityTable } from "../../src/Dashboard/dashboard/devices/activity-table.js";

/**
 * O stream redesenha o detalhe a cada mensagem, sem comparar o que mudou. Substituir o
 * `innerHTML` deita fora o elemento com o foco, e chegar de Tab a uma linha para a abrir com
 * Enter passa a ser impossível num aparelho vivo.
 */
const rows = [
    {
        icon: "fa-heart",
        name: "Frequência cardíaca",
        value: "72 bpm",
        detail: "",
        detailKind: "text",
        detailTitle: "",
        expanded: "<div>mais</div>",
        key: "t:aaa111:heart_rate:1",
        at: "2026-10-01T09:00:00Z",
        time: "09:00",
        timeTitle: "1 de outubro, 09:00",
    },
];

function mounted() {
    const root = document.createElement("div");
    document.body.replaceChildren(root);
    activityTable(root, rows, "Sem nada.", "t");
    return root;
}

test("redesenhar com as mesmas linhas não deita fora o elemento com o foco", () => {
    const root = mounted();
    const openable = root.querySelector("[data-row-toggle]");
    assert.ok(openable, "a linha tinha de ser abrível");

    openable.focus();
    assert.equal(document.activeElement, openable);

    activityTable(root, rows, "Sem nada.", "t");

    assert.equal(document.activeElement, openable, "o foco tem de sobreviver ao redesenho");
});

test("uma linha nova continua a entrar", () => {
    const root = mounted();
    const before = root.querySelectorAll("[data-row-toggle]").length;

    activityTable(root, [...rows, { ...rows[0], key: "t:aaa111:heart_rate:2", value: "80 bpm" }],
        "Sem nada.", "t");

    assert.equal(root.querySelectorAll("[data-row-toggle]").length, before + 1);
    assert.match(root.textContent, /80 bpm/);
});

/**
 * Quando chega mesmo uma leitura nova a lista tem de ser reescrita, e a linha focada morre
 * com ela. A chave do registo é a mesma antes e depois, e é por ela que o foco volta.
 */
test("uma leitura nova não rouba o foco à linha que estava escolhida", () => {
    const root = mounted();
    const openable = root.querySelector("[data-row-toggle]");
    const key = openable.dataset.rowKey;
    openable.focus();

    activityTable(root, [{ ...rows[0], key: "t:aaa111:heart_rate:2", value: "80 bpm" }, ...rows],
        "Sem nada.", "t");

    assert.equal(
        document.activeElement?.dataset?.rowKey,
        key,
        "o foco tem de voltar à linha da mesma leitura",
    );
});

/** Sem foco na lista, redesenhar não vai roubá-lo a quem o tiver noutro sítio. */
test("redesenhar não traz o foco para a lista", () => {
    const root = mounted();
    const outside = document.createElement("button");
    document.body.append(outside);
    outside.focus();

    activityTable(root, [{ ...rows[0], key: "t:aaa111:heart_rate:9" }], "Sem nada.", "t");

    assert.equal(document.activeElement, outside);
});
