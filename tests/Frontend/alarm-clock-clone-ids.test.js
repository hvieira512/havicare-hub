import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { appendRepeatRow } from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

const ENTRY = { input: "alarm_clock", key: "alarm_clock", fields: [] };

const idsIn = (row) => [...row.querySelectorAll("[id]")].map((el) => el.id);
// Só os campos: o `name` do `<details>` é o grupo que fecha as irmãs, e esse é para partilhar.
const namesIn = (row) =>
    [...row.querySelectorAll("input[name], select[name], textarea[name]")].map((el) => el.name);

/**
 * A linha do alarme nasce por clonagem: com `id` repetidos, os rádios dos dois alarmes formam
 * um grupo só e os `for` do segundo apontam para as caixas do primeiro.
 */
test("um alarme acrescentado não repete os id nem os grupos do anterior", () => {
    const section = configSection(renderConfigInputs, ENTRY, {}, { limit: 3 });
    appendRepeatRow(section, "alarm_clock");

    const [first, second] = section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]");
    assert.ok(second, "a segunda linha nasceu");

    const shared = idsIn(first).filter((id) => idsIn(second).includes(id));
    assert.deepEqual(shared, [], "nenhum `id` é partilhado pelas duas linhas");

    const sharedNames = namesIn(first).filter((name) => namesIn(second).includes(name));
    assert.deepEqual(sharedNames, [], "nenhum grupo de rádio é partilhado");
});

test("as etiquetas de um alarme apontam para dentro da própria linha", () => {
    const section = configSection(renderConfigInputs, ENTRY, {}, { limit: 3 });
    appendRepeatRow(section, "alarm_clock");

    for (const row of section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]")) {
        for (const label of row.querySelectorAll("label[for]")) {
            assert.ok(
                row.querySelector(`#${CSS.escape(label.htmlFor)}`),
                `a etiqueta "${label.textContent.trim()}" aponta para fora da linha`,
            );
        }
    }
});
