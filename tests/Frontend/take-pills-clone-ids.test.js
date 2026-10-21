import test from "node:test";
import assert from "node:assert/strict";

import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

const TAKE_PILLS = { input: "takePills", key: "medication_reminders", capabilityKey: "medication_reminders", fields: [], limit: 3 };

const rowsOf = (section) => [...section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]")];

const attrsIn = (row, attr) => [...row.querySelectorAll(`[${attr}]`)].map((el) => el.getAttribute(attr));

/** Tirar um lembrete do meio e acrescentar outro não pode juntar os rádios de duas linhas. */
test("a reminder added after removing another does not reuse ids", () => {
    const section = configSection(renderConfigInputs, TAKE_PILLS, {}, { limit: 3 });
    appendRepeatRow(section, "alarm_clock");
    appendRepeatRow(section, "alarm_clock");
    assert.equal(rowsOf(section).length, 3);

    removeRepeatRow(rowsOf(section)[1].querySelector("[data-action=\"removeRepeatRow\"]"));
    appendRepeatRow(section, "alarm_clock");
    const rows = rowsOf(section);
    assert.equal(rows.length, 3);

    const ids = rows.flatMap((row) => attrsIn(row, "id"));
    assert.equal(new Set(ids).size, ids.length, `id repetidos: ${ids.filter((id, i) => ids.indexOf(id) !== i)}`);

    const groupsPerRow = rows.map((row) => new Set(
        [...row.querySelectorAll("input[name], select[name], textarea[name]")].map((element) => element.getAttribute("name")),
    ));
    for (const [index, groups] of groupsPerRow.entries()) {
        for (const [other, otherGroups] of groupsPerRow.entries()) {
            if (index >= other) continue;
            assert.deepEqual([...groups].filter((name) => otherGroups.has(name)), [], `linhas ${index} e ${other}`);
        }
    }
});

const threeReminders = () => configSection(renderConfigInputs, TAKE_PILLS, {
    plans: ["09:00", "13:00", "21:00"].map((time) => ({ times: [{ time, enabled: true, recurrence: { kind: "daily" } }] })),
    voiceData: "AAAA",
}, { limit: 3 });

/** O 4P Touch aplica a mesma gravação a todos os lembretes, e nenhum deles tem a sua. */
test("there is one voice recording for all reminders", () => {
    const section = threeReminders();

    assert.equal(rowsOf(section).length, 3);
    assert.equal(section.querySelectorAll("[data-config-field=\"voiceData\"]").length, 1);
    assert.equal(section.querySelectorAll("[data-action=\"takePillsRecord\"]").length, 1);
});

test("no reminder carries a voice inside or a sign of having one", () => {
    for (const row of rowsOf(threeReminders())) {
        assert.equal(row.querySelector("[data-takepills-audio-controls]"), null, "voz dentro do lembrete");
        assert.equal(row.querySelector("[data-alarm-line-badges]").textContent.trim(), "", "sinal de voz na linha fechada");
    }
});
