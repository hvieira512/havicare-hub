import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { syncConfigSectionDirty } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * Uma configuração cuja entrega falhou tem de poder ser reenviada.
 *
 * O «Enviar» de um cartão acende-se por diferença, com três excepções: uma acção, que é sempre
 * um pedido novo; uma definição que o aparelho nunca recebeu; e uma definição **guardada**
 * cuja entrega falhou, que coincide consigo própria e ficaria sem caminho para sair do ecrã.
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

const enviarEstaVivo = (el) => {
    syncConfigSectionDirty(el);
    return !el.querySelector("[data-action=\"saveConfig\"]").disabled;
};

test("sem alterações e sem falha, não há nada para enviar", () => {
    assert.equal(enviarEstaVivo(section()), false);
});

test("com o valor alterado, envia-se", () => {
    assert.equal(enviarEstaVivo(section({ value: 2 })), true);
});

test("uma entrega falhada pode ser repetida sem se mexer no valor", () => {
    assert.equal(enviarEstaVivo(section({ delivery: "failed" })), true);
});

test("uma definição nunca enviada continua a poder sair do ecrã", () => {
    assert.equal(enviarEstaVivo(section({ stored: "0" })), true);
});
