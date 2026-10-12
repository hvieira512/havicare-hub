import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    readConfigPayload,
    renderConfigInputs,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/**
 * Vivistar escreve os dias em dígitos, Wonlex numa máscara com a segunda na posição 0 e 4P
 * Touch com o **domingo** na posição 0; errar é um alarme a tocar no dia errado.
 */

// O domingo é o dia que a máscara do 4P põe na posição 0, ao contrário dos outros.
const WEEKDAYS = [
    { day: 1, name: "segunda" },
    { day: 7, name: "domingo" },
];

const renderAndRead = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

/* ---------- o construtor partilhado: Vivistar e Wonlex ---------- */

const SHARED_ENTRY = { input: "alarm_clock", key: "alarm_clock" };

/* ---------- o 4P Touch: lembrete de comprimidos ---------- */

/*
 * A posição do dia na máscara nativa prende-se no contrato; aqui prende-se a ida e volta ao
 * formulário.
 */

const TAKE_PILLS_ENTRY = { input: "takePills", key: "take_pills" };

for (const { day, name } of WEEKDAYS) {
    test(`take_pills escreve a ${name} na posição certa da máscara`, () => {
        const payload = renderAndRead(
            TAKE_PILLS_ENTRY,
            {
                reminderText: "Tomar",
                plans: [
                    { times: [{ time: "08:00", enabled: true, recurrence: { kind: "custom", days: [day] } }] },
                ],
            },
            { limit: 3 },
        );

        assert.deepEqual(payload.plans[0].times[0].recurrence, { kind: "custom", days: [day] }, name);
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
