import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { resetConfigPane } from "../../src/Dashboard/dashboard/devices/config/handlers.js";

/**
 * Num grupo de rádios o Repor devolve qual opção está marcada; escrever o valor guardado em
 * todas faria o grupo enviar sempre o mesmo.
 */
function pane(pristine) {
    return parseFragment(`
        <section data-config-section data-config-pristine='${pristine}'>
            <input type="radio" name="soundProfile" value="1" data-config-field="mode">
            <input type="radio" name="soundProfile" value="2" data-config-field="mode" checked>
            <input type="radio" name="soundProfile" value="3" data-config-field="mode">
        </section>`);
}

const values = (root) => [...root.querySelectorAll("input")].map((input) => input.value);
const checked = (root) => root.querySelector("input:checked")?.value ?? "";

test("o Repor não reescreve o valor das opções de um grupo de rádios", () => {
    const root = pane(JSON.stringify({ mode: "2" }));

    resetConfigPane(root);

    assert.deepEqual(values(root), ["1", "2", "3"]);
});

test("o Repor devolve a marca à opção que estava guardada", () => {
    const root = pane(JSON.stringify({ mode: "3" }));
    root.querySelector("input[value=\"1\"]").checked = true;

    resetConfigPane(root);

    assert.equal(checked(root), "3");
});
