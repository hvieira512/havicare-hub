import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { buttonGroup } from "../../src/Dashboard/dashboard/components/button-group.js";

/**
 * Um grupo de botões que devolve HTML em vez de o escrever no elemento que recebeu.
 *
 * Escrever no contentor impedia-o de ser composto dentro de outra marcação, e era o que o
 * separava dos outros componentes: os seis sítios que o usam já tinham o elemento na mão e já
 * faziam `innerHTML` para tudo o resto.
 */
const ITEMS = [
    { value: "watch", label: "Relógio" },
    { value: "radar", label: "Radar" },
];

test("o item escolhido destaca-se dos outros", () => {
    const root = parseFragment(buttonGroup(ITEMS, "radar", "pickType"));
    const [watch, radar] = root.querySelectorAll("button");

    assert.ok(radar.classList.contains("btn-primary"));
    assert.ok(watch.classList.contains("btn-outline-primary"));
    assert.equal(radar.dataset.action, "pickType");
    assert.equal(radar.dataset.value, "radar");
});

test("uma lista vazia diz que não há opções, em vez de não desenhar nada", () => {
    const root = parseFragment(buttonGroup([], "", "pickType"));

    assert.equal(root.querySelectorAll("button").length, 0);
    assert.match(root.textContent, /Sem opções disponíveis/);
});

test("as chaves do valor e do rótulo podem ser outras", () => {
    const root = parseFragment(
        buttonGroup([{ id: "a", nome: "Primeiro" }], "a", "pickType", "id", "nome"),
    );
    const button = root.querySelector("button");

    assert.equal(button.dataset.value, "a");
    assert.equal(button.textContent.trim(), "Primeiro");
});
