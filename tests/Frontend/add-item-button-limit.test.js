import test from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";

import "./support/browser-env.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";

/**
 * O sync é genérico -- corre para qualquer tipo repetível -- e por isso vale também para os
 * `keepLast`, como os contactos SOS e a whitelist.
 */
test("the add-item button tracks the limit for a rendered kind (wonlexMedicationPlan)", () => {
    const dom = new JSDOM(
        `<!doctype html><body>
            <div data-config-section>
                <button data-action="addRepeatRow" data-repeat-kind="wonlexMedicationPlan">Adicionar item</button>
                <div data-repeat-list="wonlexMedicationPlan" data-repeat-limit="2"></div>
            </div>
        </body>`,
    );
    const section = dom.window.document.querySelector("[data-config-section]");
    const button = section.querySelector("[data-action=\"addRepeatRow\"]");

    appendRepeatRow(section, "wonlexMedicationPlan");
    assert.equal(button.disabled, false, "abaixo do limite o botão fica ligado");

    appendRepeatRow(section, "wonlexMedicationPlan");
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
