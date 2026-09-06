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
 * e por isso o sync saía sempre e o botão estagnava por ser usado. O sync passou a ser genérico
 * -- corre para qualquer tipo repetível --, por isso vale também para os `keepLast` (contactos
 * SOS, whitelist) que nunca tiveram sync nenhum.
 */
test("the add-item button tracks the limit for a rendered kind (fourPTouchAlarm)", () => {
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
    assert.equal(button.disabled, false, "abaixo do limite o botão fica ligado");

    appendRepeatRow(section, "fourPTouchAlarm");
    assert.equal(button.disabled, true, "no limite o botão desativa");

    const removeButton = section.querySelector("[data-action=\"removeRepeatRow\"]");
    assert.ok(removeButton, "a linha traz um botão de remover");
    removeRepeatRow(removeButton);
    assert.equal(button.disabled, false, "ao remover uma linha o botão volta a ligar");
});

test("the sync is generic: a keepLast kind (sos_contacts) also tracks its limit", () => {
    const dom = new JSDOM(
        `<!doctype html><body>
            <div data-config-section>
                <button data-action="addRepeatRow" data-repeat-kind="sos_contacts">Adicionar contacto SOS</button>
                <div data-repeat-list="sos_contacts" data-repeat-limit="2">
                    <div data-repeat-row="sos_contacts"><input value="911"><button data-action="removeRepeatRow">x</button></div>
                </div>
            </div>
        </body>`,
    );
    const section = dom.window.document.querySelector("[data-config-section]");
    const button = section.querySelector("[data-action=\"addRepeatRow\"]");
    const list = section.querySelector("[data-repeat-list=\"sos_contacts\"]");

    appendRepeatRow(section, "sos_contacts");
    assert.equal(list.querySelectorAll("[data-repeat-row=\"sos_contacts\"]").length, 2);
    assert.equal(button.disabled, true, "no limite o contacto SOS desativa, mesmo sem sync próprio");

    removeRepeatRow(list.querySelector("[data-repeat-row=\"sos_contacts\"] [data-action=\"removeRepeatRow\"]"));
    assert.equal(button.disabled, false, "ao remover volta a ligar");
});
