import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { buttonGroup } from "../../src/Dashboard/dashboard/components/button-group.js";

/**
 * Um grupo de botões que devolve HTML em vez de o escrever no elemento que recebeu: escrever
 * no contentor impede-o de ser composto dentro de outra marcação.
 */
const ITEMS = [
    { value: "watch", label: "Relógio" },
    { value: "radar", label: "Radar" },
];

test("the chosen item stands out from the others", () => {
    const root = parseFragment(buttonGroup(ITEMS, "radar", "pickType"));
    const [watch, radar] = root.querySelectorAll("button");

    assert.equal(radar.getAttribute("aria-pressed"), "true");
    assert.equal(watch.getAttribute("aria-pressed"), "false");
    assert.equal(radar.dataset.action, "pickType");
    assert.equal(radar.dataset.value, "radar");
});

test("an empty list says there are no options instead of drawing nothing", () => {
    const root = parseFragment(buttonGroup([], "", "pickType"));

    assert.equal(root.querySelectorAll("button").length, 0);
    assert.match(root.textContent, /Sem opções disponíveis/);
});
