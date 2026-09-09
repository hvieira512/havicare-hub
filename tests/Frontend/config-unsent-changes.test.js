import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { unsentConfigChanges } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * Fechar o diálogo com configuração escrita e não enviada deitava-a fora em silêncio.
 *
 * A conta tem de ser do que alguém editou, e só disso. Uma acção é sempre um pedido novo, e
 * uma definição que o aparelho ainda não recebeu está por enviar sem ninguém lhe ter tocado
 * -- avisar por causa delas era avisar em todos os relógios, todas as vezes, e um aviso que
 * aparece sempre deixa de se ler.
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

test("um bloco por tocar não conta", () => {
    const host = root();
    section(host, { value: 15, pristine: JSON.stringify({ minutes: 15 }) });

    assert.equal(unsentConfigChanges(host), 0);
});

test("um bloco editado conta", () => {
    const host = root();
    section(host, { value: 30, pristine: JSON.stringify({ minutes: 15 }) });

    assert.equal(unsentConfigChanges(host), 1);
});

test("uma acção nunca conta, mesmo estando sempre pronta a enviar", () => {
    const host = root();
    section(host, { value: 30, pristine: JSON.stringify({ minutes: 15 }), transient: "1" });

    assert.equal(unsentConfigChanges(host), 0);
});

test("uma definição que o aparelho nunca recebeu não conta se ninguém lhe tocou", () => {
    const host = root();
    section(host, { value: 15, pristine: JSON.stringify({ minutes: 15 }), stored: "0" });

    assert.equal(unsentConfigChanges(host), 0);
});

test("as linhas de um grupo contam uma a uma", () => {
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
