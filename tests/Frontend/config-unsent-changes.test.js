import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import { unsentConfigChanges } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * A conta é só do que alguém editou: uma acção ou uma definição que o aparelho ainda não
 * recebeu avisaria em todos os relógios, todas as vezes, e o aviso deixaria de se ler.
 */
const root = () => {
    const element = document.createElement("div");
    document.body.replaceChildren(element);
    return element;
};

const section = (root_, { value, pristine, stored = "1", transient = "0" }) => {
    const element = document.createElement("div");
    element.dataset.configSection = "health";
    element.dataset.configKey = "step_reporting_interval";
    element.dataset.configInput = "number";
    element.dataset.configStored = stored;
    element.dataset.configTransient = transient;
    if (pristine !== undefined) element.dataset.configPristine = pristine;
    element.innerHTML = `<input data-config-field="minutes" value="${value}">`;
    root_.appendChild(element);
    return element;
};

test("an untouched block does not count", () => {
    const host = root();
    section(host, { value: 15, pristine: JSON.stringify({ minutes: 15 }) });

    assert.equal(unsentConfigChanges(host), 0);
});

test("an edited block counts", () => {
    const host = root();
    section(host, { value: 30, pristine: JSON.stringify({ minutes: 15 }) });

    assert.equal(unsentConfigChanges(host), 1);
});

test("an action never counts, even though it is always ready to send", () => {
    const host = root();
    section(host, { value: 30, pristine: JSON.stringify({ minutes: 15 }), transient: "1" });

    assert.equal(unsentConfigChanges(host), 0);
});

test("a setting the device never received does not count if nobody touched it", () => {
    const host = root();
    section(host, { value: 15, pristine: JSON.stringify({ minutes: 15 }), stored: "0" });

    assert.equal(unsentConfigChanges(host), 0);
});

test("the rows of a group count one by one", () => {
    const host = root();
    const group = document.createElement("div");
    group.dataset.configGroup = "health";
    host.appendChild(group);

    for (const [value, pristine] of [[1, 1], [1, 0], [0, 0]]) {
        const row = document.createElement("div");
        row.dataset.configRow = "";
        row.dataset.configKey = `toggle_${value}_${pristine}`;
        row.dataset.configInput = "number";
        row.dataset.configStored = "1";
        row.dataset.configPristine = JSON.stringify({ on: pristine });
        row.innerHTML = `<input data-config-field="on" value="${value}">`;
        group.appendChild(row);
    }

    assert.equal(unsentConfigChanges(host), 1);
});

/**
 * Um bloco cuja leitura lança conta como alteração por enviar, como na fotografia e no botão:
 * senão o rodapé cala-se e ninguém chega à mensagem.
 */
function jsonRoot(json) {
    return parseFragment(`
        <div data-config-pane="geral">
            <section data-config-section data-config-key="raw" data-config-input="json"
                     data-config-pristine='{"a":1}'>
                <textarea data-config-field="json">${json}</textarea>
            </section>
        </div>`);
}

test("a block with an invalid reading counts as an unsent change", () => {
    assert.equal(unsentConfigChanges(jsonRoot("{ nao e json")), 1);
});
