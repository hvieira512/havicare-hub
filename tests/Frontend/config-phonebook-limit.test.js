import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { appendRepeatRow } from "../../src/Dashboard/dashboard/devices/config/row-editing.js";

/** O limite da lista telefónica diz-se uma vez, no contador do botão, como nos alarmes. */
const PHONEBOOK = {
    key: "phonebook",
    capabilityKey: "phonebook",
    command: "PHB",
    label: "Lista telefónica",
    input: "phonebook",
    limit: 100,
};

const render = () => parseFragment(renderConfigSection("four-p-touch", PHONEBOOK, { contacts: [] }, {}, false, null, true))
    .querySelector("[data-config-section]");

test("a lista telefónica mostra o limite só no contador", () => {
    const section = render();

    assert.doesNotMatch(section.textContent, /limite|contactos máximos/);
    assert.equal(section.querySelector("[data-repeat-count]")?.textContent, "(1 de 100)");
});

test("o contador acompanha as linhas acrescentadas", () => {
    const section = render();

    appendRepeatRow(section, "contacts");

    assert.equal(section.querySelector("[data-repeat-count]")?.textContent, "(2 de 100)");
});
