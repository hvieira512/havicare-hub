import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { syncConfigSectionDirty } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * O «Enviar» de uma acção sem parâmetros.
 *
 * O botão acende por diferença entre o que o formulário tem e a fotografia tirada ao
 * desenhar. Uma acção como «Encontrar dispositivo» não tem campos: o payload é sempre vazio,
 * a diferença nunca existe, e o botão ficava desactivado para sempre -- a acção não tinha
 * caminho nenhum na interface. Vale para os dois desenhos sem parâmetros, o `requestAction`
 * e o `resetAction`.
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
    for (const input of ["requestAction", "resetAction"]) {
        const root = section(input);

        syncConfigSectionDirty(root);

        assert.equal(button(root).disabled, false, `${input} devia poder ser enviada`);
        assert.ok(button(root).classList.contains("btn-primary"), `${input} devia acender`);
    }
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
 * Uma acção com parâmetros também está sempre pronta.
 *
 * O «Encontrar dispositivo» da pulseira Veepoo é um interruptor: ligado manda vibrar,
 * desligado manda parar. Mas continua a ser uma acção e não uma definição -- não há estado
 * guardado com que comparar, e a regra da diferença deixava o botão apagado desde o
 * princípio. Quem manda aqui é ser transiente, não o tipo de campo.
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
 * Uma definição que o aparelho ainda não recebeu pode ser enviada tal como está.
 *
 * Enquanto nada foi gravado, o que o cartão mostra é o valor por omissão do catálogo e não o
 * que está no aparelho. A regra da diferença comparava-o consigo próprio e apagava o botão:
 * a pulseira ficava sem forma de receber a primeira configuração, e o ecrã dizia PADRÃO para
 * sempre sem caminho nenhum para sair daí.
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
