import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    readConfigPayload,
    renderConfigInputs,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/**
 * Os sete dias da semana, por marca, ida e volta.
 *
 * Cada fabricante escreve os dias de uma maneira: o Vivistar em dígitos dos dias escolhidos,
 * o Wonlex numa máscara de sete com a segunda na posição 0, e o 4P Touch numa máscara de sete
 * com o **domingo** na posição 0. A interface marca sempre 1 a 7, de segunda a domingo, e é
 * na fronteira que a conversão acontece.
 *
 * Errar aqui é um alarme a tocar no dia errado, e por isso a tabela é exaustiva.
 */

const WEEKDAYS = [
    { day: 1, name: "segunda" },
    { day: 2, name: "terça" },
    { day: 3, name: "quarta" },
    { day: 4, name: "quinta" },
    { day: 5, name: "sexta" },
    { day: 6, name: "sábado" },
    { day: 7, name: "domingo" },
];

/** A máscara do 4P Touch: sete posições a começar no domingo. */
const fourPTouchMask = (day) => {
    const position = day === 7 ? 0 : day;
    return Array.from({ length: 7 }, (_, index) => (index === position ? "1" : "0")).join("");
};

const renderAndRead = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

/* ---------- o construtor partilhado: Vivistar e Wonlex ---------- */

const SHARED_ENTRY = { input: "alarm_clock", key: "alarm_clock" };

for (const { day, name } of WEEKDAYS) {
    test(`alarm_clock leva a ${name} ao fio e traz de volta a ${name}`, () => {
        const payload = renderAndRead(
            SHARED_ENTRY,
            { items: [{ time: "08:30", enabled: true, recurrence: { kind: "custom", days: [day] } }] },
            { limit: 3 },
        );

        assert.deepEqual(payload.items[0].recurrence, { kind: "custom", days: [day] }, name);
    });
}

/* ---------- o 4P Touch: lembrete de comprimidos ---------- */

const TAKE_PILLS_ENTRY = { input: "takePills", key: "take_pills" };

for (const { day, name } of WEEKDAYS) {
    test(`take_pills escreve a ${name} na posição certa da máscara`, () => {
        const payload = renderAndRead(
            TAKE_PILLS_ENTRY,
            {
                reminderText: "Tomar",
                reminderSettings: [
                    { time: "08:00", enabled: true, frequency: 3, recurrence: { kind: "custom", days: [day] } },
                ],
            },
            { limit: 3 },
        );

        assert.equal(payload.reminderSettings[0].custom, fourPTouchMask(day), name);
    });
}

/* ---------- a semana toda, e o dia que ninguém escolheu ---------- */

test("os sete dias marcados de uma vez ficam todos na recorrência", () => {
    const alarms = renderAndRead(
        SHARED_ENTRY,
        { items: [{ time: "08:30", enabled: true, recurrence: { kind: "custom", days: [1, 2, 3, 4, 5, 6, 7] } }] },
        { limit: 3 },
    );

    assert.deepEqual(alarms.items[0].recurrence.days, [1, 2, 3, 4, 5, 6, 7]);
});

test("a semana de trabalho deixa o sábado e o domingo de fora", () => {
    const alarms = renderAndRead(
        SHARED_ENTRY,
        { items: [{ time: "08:30", enabled: true, recurrence: { kind: "custom", days: [1, 2, 3, 4, 5] } }] },
        { limit: 3 },
    );

    assert.deepEqual(alarms.items[0].recurrence.days, [1, 2, 3, 4, 5]);
});

test("nenhum dia marcado numa recorrência personalizada é recusado, não gravado a zeros", () => {
    assert.throws(
        () => renderAndRead(
            SHARED_ENTRY,
            { items: [{ time: "08:30", enabled: true, recurrence: { kind: "custom", days: [] } }] },
            { limit: 3 },
        ),
        /pelo menos um dia/i,
    );
});
