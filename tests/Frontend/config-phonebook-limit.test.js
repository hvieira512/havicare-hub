import assert from "node:assert/strict";
import test from "node:test";

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

test("the counter follows the added rows", () => {
    const section = render();

    appendRepeatRow(section, "contacts");

    assert.equal(section.querySelector("[data-repeat-count]")?.textContent, "(2 de 100)");
});
