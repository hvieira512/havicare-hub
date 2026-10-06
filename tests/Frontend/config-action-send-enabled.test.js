import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { syncConfigSectionDirty } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * O «Enviar» acende pela diferença ao formulário desenhado; uma acção sem campos nunca tem
 * diferença, e por isso está sempre pronta.
 */
function section(
    input,
    fields = "",
    pristine = "{}",
    transient = true,
    stored = "1",
) {
    return parseFragment(`
        <section data-config-section data-config-input="${input}" data-config-key="find_device"
                 data-config-stored="${stored}"
                 ${transient ? "data-config-transient=\"1\"" : ""} data-config-pristine='${pristine}'>
            ${fields}
            <button type="button" class="btn btn-outline-secondary btn-sm" data-action="saveConfig"
                    data-config-phase="idle" disabled></button>
        </section>`).firstElementChild;
}

const button = (root) => root.querySelector("[data-action=\"saveConfig\"]");

test("uma acção sem parâmetros pode ser enviada", () => {
    const root = section("action");

    syncConfigSectionDirty(root);

    assert.equal(button(root).disabled, false);
    assert.ok(button(root).classList.contains("btn-primary"));
});

test("uma configuração por mexer continua a não se poder enviar", () => {
    const root = section(
        "number",
        "<input data-config-field=\"interval\" value=\"60\">",
        "{\"interval\":60}",
        false,
    );

    syncConfigSectionDirty(root);

    assert.equal(button(root).disabled, true);
});

test("uma configuração alterada pode ser enviada", () => {
    const root = section(
        "number",
        "<input data-config-field=\"interval\" value=\"90\">",
        "{\"interval\":60}",
    );

    syncConfigSectionDirty(root);

    assert.equal(button(root).disabled, false);
});

/**
 * O «Encontrar dispositivo» da Veepoo é um interruptor mas é uma acção: sem estado guardado com
 * que comparar, quem manda é ser transiente e não o tipo de campo.
 */
test("uma acção com campos continua a poder ser enviada sem os mexer", () => {
    const root = section(
        "toggle",
        `<input type="checkbox" data-config-field="enabled" checked>`,
        "{\"enabled\":true}",
    );

    syncConfigSectionDirty(root);

    assert.equal(button(root).disabled, false);
});

/** Uma configuração de verdade continua a acender só quando o valor muda. */
test("uma configuração sem alteração continua a ter o Enviar apagado", () => {
    const root = section(
        "toggle",
        `<input type="checkbox" data-config-field="enabled" checked>`,
        "{\"enabled\":true}",
        false,
    );

    syncConfigSectionDirty(root);

    assert.equal(button(root).disabled, true);
});

/**
 * Sem nada gravado o cartão mostra o valor por omissão do catálogo, e não o do aparelho:
 * compará-lo consigo próprio apagaria o botão para sempre.
 */
test("uma definição por gravar pode ser enviada sem a mexer", () => {
    const root = section(
        "toggle",
        "<input type=\"checkbox\" data-config-field=\"enabled\" checked>",
        "{\"enabled\":true}",
        false,
        "0",
    );

    syncConfigSectionDirty(root);

    assert.equal(button(root).disabled, false);
});
