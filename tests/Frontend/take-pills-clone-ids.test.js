import test from "node:test";
import assert from "node:assert/strict";

import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

const TAKE_PILLS = { input: "takePills", key: "take_pills", fields: [] };

const rowsOf = (section) =>
    [...section.querySelectorAll("[data-repeat-row=\"takePillsReminder\"]")];

const attrsIn = (row, attr) => [...row.querySelectorAll(`[${attr}]`)].map((el) => el.getAttribute(attr));

/**
 * O lembrete é desenhado com `rows.length` no `id` e no `name`: tirar um do meio faria o
 * seguinte reutilizar um índice vivo e juntar os rádios de duas linhas num grupo.
 */
test("a reminder added after removing another does not reuse ids", () => {
    const section = configSection(renderConfigInputs, TAKE_PILLS, {}, { limit: 3 });
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

    // Os rádios de uma linha partilham o `name` de propósito, mas duas linhas não; o `name` do
    // `<details>` fica de fora, porque é o grupo que fecha as irmãs.
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

const threeReminders = () => configSection(renderConfigInputs, TAKE_PILLS, {
    reminderSettings: [{ time: "09:00" }, { time: "13:00" }, { time: "21:00" }],
    voiceData: "AAAA",
});

/** O 4P Touch aplica a mesma gravação a todos os lembretes, e nenhum deles tem a sua. */
test("there is one voice recording for all reminders", () => {
    const section = threeReminders();

    assert.equal(section.querySelectorAll("[data-repeat-row=\"takePillsReminder\"]").length, 3);
    assert.equal(section.querySelectorAll("[data-config-field=\"voiceData\"]").length, 1);
    assert.equal(section.querySelectorAll("[data-action=\"takePillsRecord\"]").length, 1);
});

test("no reminder carries a voice inside or a sign of having one", () => {
    for (const row of threeReminders().querySelectorAll("[data-repeat-row=\"takePillsReminder\"]")) {
        assert.equal(row.querySelector("[data-takepills-audio-controls]"), null, "voz dentro do lembrete");
        assert.equal(
            row.querySelector("[data-alarm-line-badges]").textContent.trim(),
            "",
            "sinal de voz na linha fechada",
        );
    }
});
