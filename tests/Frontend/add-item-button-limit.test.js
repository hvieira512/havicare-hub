import test from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";

import "./support/browser-env.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";

/**
 * O botão «Adicionar item» tem de acompanhar o limite: desativa quando se chega a ele e volta
 * a ligar quando se remove uma linha. Estava a procurar um `data-action` que o botão não tem,
 * e por isso o sync saía sempre e o botão estagnava por ser usado.
 */
test("the add-item button tracks the limit as rows are added and removed", () => {
    const dom = new JSDOM(
        `<!doctype html><body>
            <div data-config-section>
                <button data-action="addRepeatRow" data-repeat-kind="fourPTouchAlarm">Adicionar item</button>
                <div data-repeat-list="fourPTouchAlarm" data-repeat-limit="2"></div>
            </div>
        </body>`,
    );
    const section = dom.window.document.querySelector("[data-config-section]");
    const button = section.querySelector("[data-action=\"addRepeatRow\"]");

    appendRepeatRow(section, "fourPTouchAlarm");
    assert.equal(button.disabled, false, "com uma linha, abaixo do limite, o botão fica ligado");

    appendRepeatRow(section, "fourPTouchAlarm");
    assert.equal(button.disabled, true, "no limite o botão desativa");

    const removeButton = section.querySelector("[data-action=\"removeRepeatRow\"]");
    assert.ok(removeButton, "a linha traz um botão de remover");
    removeRepeatRow(removeButton);
    assert.equal(button.disabled, false, "ao remover uma linha o botão volta a ligar");
});
