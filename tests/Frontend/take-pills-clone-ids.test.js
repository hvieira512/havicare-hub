import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

const ENTRY = { input: "takePills", key: "take_pills", fields: [] };

const rowsOf = (section) =>
    [...section.querySelectorAll("[data-repeat-row=\"takePillsReminder\"]")];

const attrsIn = (row, attr) => [...row.querySelectorAll(`[${attr}]`)].map((el) => el.getAttribute(attr));

/**
 * O lembrete é desenhado de novo com `rows.length` no `id` e no `name`. Tirar um do meio faz
 * o seguinte reutilizar um índice ainda vivo: os rádios dos dois passam a ser um grupo só, e
 * o `readTakePills` não encontra nenhum marcado no grupo que perdeu -- gravava «Uma vez».
 */
test("um lembrete acrescentado depois de remover outro não reutiliza os id", () => {
    const section = configSection(renderConfigInputs, ENTRY, {}, { limit: 3 });
    while (rowsOf(section).length < 3) {
        const before = rowsOf(section).length;
        appendRepeatRow(section, "takePillsReminder");
        assert.ok(rowsOf(section).length > before, "o lembrete foi acrescentado");
    }

    removeRepeatRow(rowsOf(section)[1].querySelector("[data-action=\"removeRepeatRow\"]"));
    assert.equal(rowsOf(section).length, 2, "o do meio saiu");

    appendRepeatRow(section, "takePillsReminder");
    const rows = rowsOf(section);
    assert.equal(rows.length, 3, "e entrou um novo");

    const ids = rows.flatMap((row) => attrsIn(row, "id"));
    assert.equal(new Set(ids).size, ids.length, `id repetidos: ${ids.filter((id, i) => ids.indexOf(id) !== i)}`);

    // Dentro de uma linha os rádios partilham o `name` de propósito -- é o que os torna um
    // grupo. O que não pode acontecer é duas linhas caírem no mesmo.
    // O `name` do `<details>` fica de fora: esse é o grupo que fecha as irmãs, e é partilhado.
    const groupsPerRow = rows.map((row) => new Set(
        [...row.querySelectorAll("input[name], select[name], textarea[name]")]
            .map((element) => element.getAttribute("name")),
    ));
    for (const [index, groups] of groupsPerRow.entries()) {
        for (const [other, otherGroups] of groupsPerRow.entries()) {
            if (index >= other) continue;
            const shared = [...groups].filter((name) => otherGroups.has(name));
            assert.deepEqual(shared, [], `linhas ${index} e ${other} partilham ${shared}`);
        }
    }
});
