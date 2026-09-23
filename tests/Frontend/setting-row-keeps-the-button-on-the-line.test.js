import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { settingRow } from "../../src/Dashboard/dashboard/components/setting-row.js";

/**
 * O botão de uma definição fica na linha do nome, por mais longa que seja a descrição.
 *
 * A linha é um `flex-wrap`, e um bloco de título com a base no conteúdo reclama a largura
 * toda e manda o botão para a linha de baixo. O que o corrige é a base a zero -- `flex: 1 1
 * 0` --, e é a declaração que se prende aqui, porque o teste não tem motor de layout.
 */
const LONG_NOTE = "Pergunta ao aparelho que configurações ele tem lá dentro e mostra-as aqui." +
    " Não muda nada: serve para confirmar que o que está no ecrã é mesmo o que o aparelho" +
    " ficou a ter.";

const row = (note) => parseFragment(settingRow({
    title: "Sincronizar configuração",
    note,
    actions: "<button type=\"button\" data-action=\"saveConfig\">Enviar</button>",
}));

test("o bloco do título reparte o espaço em vez de o reclamar todo", () => {
    const title = row(LONG_NOTE).querySelector(".setting-row-title");

    assert.ok(title, "o bloco do nome tem de trazer a classe que lhe dá a base a zero");
    assert.ok(title.querySelector(".fw-semibold"), "e é o bloco que leva o nome");
});

/** Sem descrição a linha é a mesma: o desenho não muda com o comprimento do texto. */
test("uma linha sem descrição usa o mesmo bloco", () => {
    assert.ok(row("").querySelector(".setting-row-title"));
});

/** O botão continua a ser o último elemento da linha, à direita do nome. */
test("o botão fica depois do nome, na mesma linha", () => {
    const line = row(LONG_NOTE).firstElementChild;
    const children = [...line.children];

    assert.ok(children[0].classList.contains("setting-row-title"));
    assert.equal(children[children.length - 1].dataset.action, "saveConfig");
});
