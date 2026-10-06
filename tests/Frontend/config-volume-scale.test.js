import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * O volume do dispensador tem quatro posições com a escala invertida (`0` é o mais alto, `3` é
 * silêncio), e por isso mostram-se todas à largura do cartão, pela ordem em que existem.
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
    const first = render({ volume: 0 });
    const second = render({ volume: 0 });
    const groupName = (root) => root.querySelector("input[type=radio]").getAttribute("name");

    // Com o mesmo nome, dois grupos na mesma página seriam um só, e escolher num desmarcaria
    // o outro.
    assert.notEqual(groupName(first), groupName(second));
});
