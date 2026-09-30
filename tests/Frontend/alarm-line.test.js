import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { refreshAlarmLine } from "../../src/Dashboard/dashboard/devices/config/alarm-fields.js";
import { appendRepeatRow } from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

/**
 * A gramática comum das cinco listas de alarmes: cada entrada é uma linha fechada com
 * interruptor, título e valor à direita, e abre-se para mostrar o resto.
 */
const ALARM = { input: "alarm_clock", key: "alarm_clock", fields: [] };
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
const TAKE_PILLS = { input: "takePills", key: "take_pills", fields: [] };
const MEDICATION = { input: "wonlexMedicationPlans", key: "medication_plan", fields: [] };

const rowsOf = (section, kind) =>
    [...section.querySelectorAll(`[data-repeat-row="${kind}"]`)];

const textOf = (row, slot) => row.querySelector(`[data-alarm-line-${slot}]`)?.textContent.trim() ?? "";

test("cada entrada das cinco listas fecha numa linha com título e valor à direita", () => {
    const forms = [
        [rowsOf(configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40" }] }), "alarm_clock"), "4P Touch"],
        [rowsOf(configSection(renderConfigInputs, ALARM, { items: [{ time: "09:00", type: 1 }] }, VIVISTAR_META), "alarm_clock"), "Vivistar"],
        [rowsOf(configSection(renderConfigInputs, ALARM, { items: [{ time: "12:17", label: "Teste" }] }, WONLEX_META), "alarm_clock"), "Wonlex"],
        [rowsOf(configSection(renderConfigInputs, TAKE_PILLS, { reminderSettings: [{ time: "09:00" }] }), "takePillsReminder"), "voz 4P Touch"],
        [rowsOf(configSection(renderConfigInputs, MEDICATION, {}), "wonlexMedicationPlan"), "plano Wonlex"],
    ];

    for (const [rows, form] of forms) {
        assert.equal(rows.length, 1, `${form}: uma entrada desenhada`);
        const [row] = rows;
        assert.ok(row.querySelector("details"), `${form}: a entrada tem de abrir e fechar`);
        assert.ok(row.querySelector("[data-alarm-line-title]"), `${form}: sem título`);
        assert.ok(row.querySelector("[data-alarm-line-trailing]"), `${form}: sem valor à direita`);
    }
});

test("o título é o nome, senão o tipo, senão a hora", () => {
    const titleOf = (desired, meta) => textOf(
        rowsOf(configSection(renderConfigInputs, ALARM, desired, meta), "alarm_clock")[0],
        "title",
    );

    assert.equal(titleOf({ items: [{ time: "12:17", label: "Teste" }] }, WONLEX_META), "Teste");
    assert.equal(titleOf({ items: [{ time: "09:00", type: 2 }] }, VIVISTAR_META), "Água");
    assert.equal(titleOf({ items: [{ time: "08:40" }] }, {}), "08:40");
});

test("a recorrência aparece em palavras no subtítulo", () => {
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

test("um sinal na linha fechada diz que o alarme tem som próprio", () => {
    const badgesOf = (item) => textOf(
        rowsOf(configSection(renderConfigInputs, ALARM, { items: [item] }, WONLEX_META), "alarm_clock")[0],
        "badges",
    );

    assert.match(badgesOf({ time: "12:17", url: "https://exemplo.pt/a.mp3" }), /♪/);
    assert.equal(badgesOf({ time: "12:17" }), "");
});

test("a linha fechada mostra o que se acabou de escrever, e não o que lá estava", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40", label: "Antigo" }] }, WONLEX_META);
    const [row] = rowsOf(section, "alarm_clock");

    row.querySelector("[data-alarm-clock-field=\"label\"]").value = "Novo";
    row.querySelector("[data-alarm-clock-field=\"time\"]").value = "21:15";
    refreshAlarmLine(row);

    assert.equal(textOf(row, "title"), "Novo");
    assert.equal(textOf(row, "trailing"), "21:15");
});

/** Sem isto a linha fechada só acertava até à primeira tecla, e o módulo é quem a liga. */
test("escrever num campo aberto refaz a linha fechada, sem ninguém chamar nada", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40", label: "Antigo" }] }, WONLEX_META);
    document.body.appendChild(section);

    const [row] = rowsOf(section, "alarm_clock");
    const label = row.querySelector("[data-alarm-clock-field=\"label\"]");
    label.value = "Novo";
    label.dispatchEvent(new window.Event("input", { bubbles: true }));

    assert.equal(textOf(row, "title"), "Novo");
    section.remove();
});

test("o plano de medicação mostra a frequência à direita, porque tem várias horas", () => {
    const section = configSection(renderConfigInputs, MEDICATION, {
        plans: [{
            drugName: "Paracetamol",
            drugDose: 500,
            drugUnit: "3",
            drugTime: { alarmClock: { Morning: "08:00", Noon: "12:00" }, checkboxes: [0, 1], radio: 0 },
        }],
    });
    const [row] = rowsOf(section, "wonlexMedicationPlan");

    assert.match(textOf(row, "title"), /Paracetamol/);
    assert.equal(textOf(row, "trailing"), "2×/dia");
});

test("o acrescentar diz quantas entradas já lá estão", () => {
    const section = configSection(renderConfigInputs, ALARM, { items: [{ time: "08:40" }] });
    const button = section.querySelector("[data-action=\"addRepeatRow\"][data-repeat-kind=\"alarm_clock\"]");

    assert.equal(button.textContent.replace(/\s+/g, " ").trim(), "Acrescentar alarme (1 de 3)");

    appendRepeatRow(section, "alarm_clock");

    assert.equal(button.textContent.replace(/\s+/g, " ").trim(), "Acrescentar alarme (2 de 3)");
});

test("só a entrada em que se mexe fica aberta", () => {
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
test("nenhuma das cinco formas perdeu um campo", () => {
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
        ["voz 4P Touch", configSection(renderConfigInputs, TAKE_PILLS, { reminderSettings: [{ time: "09:00" }] }), [
            "[data-takepills-field=\"reminderTime\"]",
            "[data-takepills-field=\"reminderEnabled\"]",
            "[data-takepills-field=\"reminderFrequency\"]",
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
        ["plano Wonlex", configSection(renderConfigInputs, MEDICATION, {}), [
            "[data-medication-field=\"drugType\"]",
            "[data-medication-field=\"drugName\"]",
            "[data-medication-field=\"drugDose\"]",
            "[data-medication-field=\"drugUnit\"]",
            "[data-medication-field=\"drugStartTime\"]",
            "[data-medication-field=\"drugEndTime\"]",
            "[data-medication-field=\"drugInterval\"]",
            "[data-medication-field=\"mealTiming\"]",
            "[data-medication-period]",
            "[data-medication-period-time=\"0\"]",
        ]],
    ];

    for (const [form, section, selectors] of cases) {
        for (const selector of selectors) {
            assert.ok(section.querySelector(selector), `${form}: perdeu ${selector}`);
        }
    }
});
