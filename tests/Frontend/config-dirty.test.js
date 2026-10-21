import assert from "node:assert/strict";
import test from "node:test";

import { syncConfigSectionDirty } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * O «Enviar» acende por diferença, excepto numa acção, numa definição que o aparelho nunca
 * recebeu e numa definição guardada cuja entrega falhou.
 */

const section = ({ stored = "1", delivery = "", pristine = { volume: 1 }, value = 1 } = {}) =>
    parseFragment(`
        <section data-config-section data-config-input="select" data-config-key="alarm_volume"
                 data-config-stored="${stored}"
                 ${delivery ? `data-config-delivery="${delivery}"` : ""}
                 data-config-pristine='${JSON.stringify(pristine)}'>
            <select data-config-field="volume">
                <option value="1" ${value === 1 ? "selected" : ""}>Médio</option>
                <option value="2" ${value === 2 ? "selected" : ""}>Baixo</option>
            </select>
            <button data-action="saveConfig" data-config-phase="idle" disabled>Enviar</button>
        </section>`).querySelector("[data-config-section]");

const actionSection = (input, fields = "", pristine = "{}") =>
    parseFragment(`
        <section data-config-section data-config-input="${input}" data-config-key="find_device"
                 data-config-stored="1" data-config-transient="1" data-config-pristine='${pristine}'>
            ${fields}
            <button type="button" data-action="saveConfig" data-config-phase="idle" disabled></button>
        </section>`).firstElementChild;

const sendEnabled = (el) => {
    syncConfigSectionDirty(el);
    return !el.querySelector("[data-action=\"saveConfig\"]").disabled;
};

test("sem alterações e sem falha, não há nada para enviar", () => {
    assert.equal(sendEnabled(section()), false);
});

test("com o valor alterado, envia-se", () => {
    assert.equal(sendEnabled(section({ value: 2 })), true);
});

test("uma entrega falhada pode ser repetida sem se mexer no valor", () => {
    assert.equal(sendEnabled(section({ delivery: "failed" })), true);
});

test("uma definição nunca enviada continua a poder sair do ecrã", () => {
    assert.equal(sendEnabled(section({ stored: "0" })), true);
});

test("uma acção sem parâmetros pode ser enviada", () => {
    assert.equal(sendEnabled(actionSection("action")), true);
});

/** O «Encontrar dispositivo» da Veepoo é um interruptor mas é uma acção: manda ser transiente. */
test("uma acção com campos continua a poder ser enviada sem os mexer", () => {
    const root = actionSection(
        "toggle",
        "<input type=\"checkbox\" data-config-field=\"enabled\" checked>",
        "{\"enabled\":true}",
    );

    assert.equal(sendEnabled(root), true);
});
