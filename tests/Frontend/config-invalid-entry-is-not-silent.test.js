import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { unsentConfigChanges } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * Um bloco cuja leitura lança conta como alteração por enviar, como na fotografia e no botão:
 * senão o rodapé cala-se e ninguém chega à mensagem.
 */
function root(json) {
    return parseFragment(`
        <div data-config-pane="geral">
            <section data-config-section data-config-key="raw" data-config-input="json"
                     data-config-pristine='{"a":1}'>
                <textarea data-config-field="json">${json}</textarea>
            </section>
        </div>`);
}

test("um bloco com leitura inválida conta como alteração por enviar", () => {
    assert.equal(unsentConfigChanges(root("{ nao e json")), 1);
});

test("um bloco igual à fotografia continua a não contar", () => {
    assert.equal(unsentConfigChanges(root(JSON.stringify({ a: 1 }))), 0);
});
