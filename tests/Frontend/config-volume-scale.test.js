import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * Uma escolha curta com significado mostra-se toda de uma vez, e à largura do cartão.
 *
 * O volume do dispensador tem quatro posições e a escala está invertida -- `0` é o mais alto
 * e `3` é silêncio. Numa lista fechada vê-se uma opção de cada vez e a escala não se lê; num
 * grupo espremido a um canto também não. À largura do cartão, com um ícone por posição,
 * vêem-se as quatro pela ordem em que existem -- que é a forma que a sensibilidade de queda
 * e o perfil de som já usavam.
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

const render = (desired) => parseFragment(CONFIG_INPUTS.volumeScale.render(entry, desired));

test("desenha um botão por opção, com o rótulo à vista", () => {
    const root = render({ volume: 1 });

    assert.deepEqual(
        [...root.querySelectorAll("label")].map((el) => el.textContent.trim()),
        ["Alto", "Médio", "Baixo", "Silêncio"],
    );
});

test("a escala ocupa a largura do cartão", () => {
    const group = render({ volume: 0 }).querySelector(".btn-group");

    assert.ok(group.classList.contains("w-100"));
});

test("cada posição tem o seu ícone", () => {
    const root = render({ volume: 0 });

    assert.equal(root.querySelectorAll("label i.fa-solid").length, 4);
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

    assert.deepEqual(CONFIG_INPUTS.volumeScale.read(root), { volume: 3 });
});

test("os botões do mesmo cartão não se misturam com os de outro", () => {
    const um = render({ volume: 0 });
    const outro = render({ volume: 0 });
    const nome = (root) => root.querySelector("input[type=radio]").getAttribute("name");

    // Sem nomes distintos, dois grupos na mesma página comportavam-se como um só e escolher
    // num desmarcava o outro.
    assert.notEqual(nome(um), nome(outro));
});
