import assert from "node:assert/strict";
import test from "node:test";

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

test("draws one button per option, with the label visible", () => {
    const root = render({ volume: 1 });

    assert.deepEqual(
        [...root.querySelectorAll("label")].map((el) => el.textContent.trim()),
        ["Alto", "Médio", "Baixo", "Silêncio"],
    );
});

test("the chosen option is the marked one", () => {
    const root = render({ volume: 2 });
    const checked = [...root.querySelectorAll("input[type=radio]")].filter((el) => el.checked);

    assert.equal(checked.length, 1);
    assert.equal(checked[0].getAttribute("value"), "2");
});

test("without a chosen value it starts from what the setting declares", () => {
    const root = render({});
    const checked = [...root.querySelectorAll("input[type=radio]")].filter((el) => el.checked);

    assert.equal(checked[0].getAttribute("value"), "0");
});

test("the value comes back as a number, which is what the TAG carries", () => {
    const root = render({ volume: 3 });

    assert.deepEqual(CONFIG_INPUTS.volumeScale.read(root), { volume: 3 });
});

test("the buttons of one card do not mix with another card's", () => {
    const first = render({ volume: 0 });
    const second = render({ volume: 0 });
    const groupName = (root) => root.querySelector("input[type=radio]").getAttribute("name");

    // Com o mesmo nome, dois grupos na mesma página seriam um só, e escolher num desmarcaria
    // o outro.
    assert.notEqual(groupName(first), groupName(second));
});
