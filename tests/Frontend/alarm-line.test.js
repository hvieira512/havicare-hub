import test from "node:test";
import assert from "node:assert/strict";

import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { refreshAlarmLine } from "../../src/Dashboard/dashboard/devices/config/alarm-fields.js";
import { appendRepeatRow } from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

/**
 * A gramática comum das quatro listas de alarmes: cada entrada é uma linha fechada com
 * interruptor, título e valor à direita, e abre-se para mostrar o resto.
 */
const ALARM = { input: "alarm_clock", key: "alarm_clock", capabilityKey: "alarm_clock", fields: [], limit: 3 };
const VIVISTAR_META = {
    type: {
        options: [
            { value: 1, label: "Medicação" },
            { value: 2, label: "Água" },
            { value: 3, label: "Sedentarismo" },
        ],
    },
};
const WONLEX_META = { label: { supported: true }, url: { supported: true }, limit: 10 };
const TAKE_PILLS = { input: "takePills", key: "medication_reminders", capabilityKey: "medication_reminders", fields: [], limit: 3 };

const rowsOf = (section, kind) =>
    [...section.querySelectorAll(`[data-repeat-row="${kind}"]`)];

const textOf = (row, slot) => row.querySelector(`[data-alarm-line-${slot}]`)?.textContent.trim() ?? "";

test("the title is the name, else the type, else the time", () => {
    const titleOf = (desired, meta) => textOf(
        rowsOf(configSection(renderConfigInputs, ALARM, desired, meta), "alarm_clock")[0],
        "title",
    );

    assert.equal(titleOf({ items: [{ time: "12:17", label: "Teste" }] }, WONLEX_META), "Teste");
    assert.equal(titleOf({ items: [{ time: "09:00", type: 2 }] }, VIVISTAR_META), "Água");
    assert.equal(titleOf({ items: [{ time: "08:40" }] }, {}), "08:40");
});

test("the recurrence appears in words in the subtitle", () => {
    const subtitleOf = (item) => textOf(
        rowsOf(configSection(renderConfigInputs, ALARM, { items: [item] }), "alarm_clock")[0],
        "subtitle",
    );

    assert.equal(subtitleOf({ time: "08:40", recurrence: { kind: "daily" } }), "todos os dias");
    assert.equal(
        subtitleOf({ time: "08:40", recurrence: { kind: "custom", days: [1, 3, 5] } }),
        "seg, qua, sex",
    );
});

test("the closed row shows what was just typed, not what was there", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40", label: "Antigo" }] }, WONLEX_META);
    const [row] = rowsOf(section, "alarm_clock");

    row.querySelector("[data-alarm-clock-field=\"label\"]").value = "Novo";
    row.querySelector("[data-alarm-clock-field=\"time\"]").value = "21:15";
    refreshAlarmLine(row);

    assert.equal(textOf(row, "title"), "Novo");
    assert.equal(textOf(row, "trailing"), "21:15");
});

/** A escuta que refaz a linha fechada é do módulo, e não de quem desenha o campo. */
test("typing in an open field rebuilds the closed row without anyone calling anything", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40", label: "Antigo" }] }, WONLEX_META);
    document.body.appendChild(section);

    const [row] = rowsOf(section, "alarm_clock");
    const label = row.querySelector("[data-alarm-clock-field=\"label\"]");
    label.value = "Novo";
    label.dispatchEvent(new window.Event("input", { bubbles: true }));

    assert.equal(textOf(row, "title"), "Novo");
    section.remove();
});

test("the add button says how many entries are already there", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40" }] });
    const button = section.querySelector("[data-action=\"addRepeatRow\"][data-repeat-kind=\"alarm_clock\"]");

    assert.equal(button.textContent.replace(/\s+/g, " ").trim(), "Acrescentar alarme (1 de 3)");

    appendRepeatRow(section, "alarm_clock");

    assert.equal(button.textContent.replace(/\s+/g, " ").trim(), "Acrescentar alarme (2 de 3)");
});

test("only the entry being edited stays open", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40" }] });
    section.querySelector("details").open = true;

    appendRepeatRow(section, "alarm_clock");

    const open = [...section.querySelectorAll("details")].filter((details) => details.open);
    assert.equal(open.length, 1, "duas abertas ao mesmo tempo");
    assert.equal(open[0], rowsOf(section, "alarm_clock")[1].querySelector("details"), "a aberta é a nova");
});

/**
 * A verificação que importa: redesenhar a linha não pode fazer desaparecer um campo que o
 * fornecedor já configurava.
 */
test("none of the five shapes lost a field", () => {
    const cases = [
        ["4P Touch", configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40" }] }), [
            "[data-alarm-clock-field=\"time\"]",
            "[data-alarm-clock-field=\"enabled\"]",
            "[data-alarm-clock-field=\"recurrenceKind\"]",
            "[data-weekday]",
            "[data-action=\"removeRepeatRow\"]",
        ]],
        ["Vivistar", configSection(renderConfigInputs, { ...ALARM, fields: ["masterEnabled"] }, { items: [{ time: "09:00", type: 1 }] }, VIVISTAR_META), [
            "[data-alarm-clock-field=\"masterEnabled\"]",
            "[data-alarm-clock-field=\"time\"]",
            "[data-alarm-clock-field=\"enabled\"]",
            "[data-alarm-clock-field=\"type\"]",
            "[data-alarm-clock-field=\"recurrenceKind\"]",
            "[data-weekday]",
        ]],
        ["Wonlex", configSection(renderConfigInputs, ALARM, { items: [{ time: "12:17", label: "Teste" }] }, WONLEX_META), [
            "[data-alarm-clock-field=\"label\"]",
            "[data-alarm-clock-field=\"time\"]",
            "[data-alarm-clock-field=\"enabled\"]",
            "[data-alarm-clock-field=\"url\"]",
            "[data-alarm-clock-field=\"recurrenceKind\"]",
            "[data-weekday]",
        ]],
        ["voz 4P Touch", configSection(renderConfigInputs, TAKE_PILLS, { plans: [{ times: [{ time: "09:00" }] }] }), [
            "[data-alarm-clock-field=\"time\"]",
            "[data-alarm-clock-field=\"enabled\"]",
            "[data-alarm-clock-field=\"recurrenceKind\"]",
            "[data-weekday]",
            "[data-config-field=\"reminderText\"]",
            "[data-config-field=\"voiceEnabled\"]",
            "[data-config-field=\"voiceData\"]",
            "[data-config-field=\"voiceMimeType\"]",
            "[data-action=\"takePillsRecord\"]",
            "[data-action=\"takePillsStop\"]",
            "[data-action=\"takePillsClear\"]",
            "[data-action=\"takePillsFile\"]",
            "[data-takepills-preview]",
        ]],
    ];

    for (const [form, section, selectors] of cases) {
        for (const selector of selectors) {
            assert.ok(section.querySelector(selector), `${form}: perdeu ${selector}`);
        }
    }
});
