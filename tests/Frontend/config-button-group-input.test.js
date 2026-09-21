import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * Uma escolha curta com significado mostra-se toda de uma vez.
 *
 * O volume do dispensador tem quatro posições e a escala está invertida -- `0` é o mais alto e
 * `3` é silêncio. Numa lista fechada vê-se uma opção de cada vez e a escala não se lê; num
 * grupo de botões vêem-se as quatro lado a lado, pela ordem em que existem.
 */

const entry = {
    fields: ["volume"],
    options: {
        volume: [
            { value: 0, label: "Alto" },
            { value: 1, label: "Médio" },
            { value: 2, label: "Baixo" },
            { value: 3, label: "Silêncio" },
        ],
        default: 0,
    },
};

const render = (desired) => parseFragment(CONFIG_INPUTS.buttonGroup.render(entry, desired));

test("desenha um botão por opção, com o rótulo à vista", () => {
    const root = render({ volume: 1 });

    assert.deepEqual(
        [...root.querySelectorAll("label")].map((el) => el.textContent.trim()),
        ["Alto", "Médio", "Baixo", "Silêncio"],
    );
});

test("a opção escolhida é a que fica marcada", () => {
    const root = render({ volume: 2 });
    const checked = [...root.querySelectorAll("input[type=radio]")].filter((el) => el.checked);

    assert.equal(checked.length, 1);
    assert.equal(checked[0].getAttribute("value"), "2");
});

test("sem valor escolhido parte do que a definição declara", () => {
    const root = render({});
    const checked = [...root.querySelectorAll("input[type=radio]")].filter((el) => el.checked);

    assert.equal(checked[0].getAttribute("value"), "0");
});

test("o valor volta como número, que é o que a TAG leva", () => {
    const root = render({ volume: 3 });

    assert.deepEqual(CONFIG_INPUTS.buttonGroup.read(root), { volume: 3 });
});

test("os botões do mesmo cartão não se misturam com os de outro", () => {
    const um = render({ volume: 0 });
    const outro = render({ volume: 0 });
    const nome = (root) => root.querySelector("input[type=radio]").getAttribute("name");

    // Sem nomes distintos, dois grupos na mesma página comportavam-se como um só e escolher
    // num desmarcava o outro.
    assert.notEqual(nome(um), nome(outro));
});
