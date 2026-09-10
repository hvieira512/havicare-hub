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
 * Errar aqui é um alarme a tocar no dia errado no pulso de alguém, e por isso a tabela é
 * exaustiva: os sete dias, nos dois sentidos, para cada construtor.
 */

const DIAS = [
    { dia: 1, nome: "segunda" },
    { dia: 2, nome: "terça" },
    { dia: 3, nome: "quarta" },
    { dia: 4, nome: "quinta" },
    { dia: 5, nome: "sexta" },
    { dia: 6, nome: "sábado" },
    { dia: 7, nome: "domingo" },
];

/** A máscara do 4P Touch: sete posições a começar no domingo. */
const mascaraFourPTouch = (dia) => {
    const posicao = dia === 7 ? 0 : dia;
    return Array.from({ length: 7 }, (_, i) => (i === posicao ? "1" : "0")).join("");
};

const desenhaELe = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

/* ---------- o construtor partilhado: Vivistar e Wonlex ---------- */

const ENTRADA_PARTILHADA = { input: "alarm_clock", key: "alarm_clock" };

for (const { dia, nome } of DIAS) {
    test(`alarm_clock leva a ${nome} ao fio e traz de volta a ${nome}`, () => {
        const payload = desenhaELe(
            ENTRADA_PARTILHADA,
            { items: [{ time: "08:30", enabled: true, recurrence: { kind: "custom", days: [dia] } }] },
            { limit: 3 },
        );

        assert.deepEqual(payload.items[0].recurrence, { kind: "custom", days: [dia] }, nome);
    });
}

/* ---------- o 4P Touch: alarmes ---------- */

const ENTRADA_ALARMES = { input: "alarms", key: "alarms" };

for (const { dia, nome } of DIAS) {
    test(`alarms escreve a ${nome} na posição certa da máscara`, () => {
        const alarmes = desenhaELe(
            ENTRADA_ALARMES,
            { alarms: [{ time: "07:45", enabled: true, mode: 3, recurrence: { kind: "custom", days: [dia] } }] },
            { limit: 3 },
        );

        assert.equal(alarmes.alarms[0].custom, mascaraFourPTouch(dia), nome);
    });
}

for (const { dia, nome } of DIAS) {
    test(`alarms lê a máscara da ${nome} de volta como ${nome}`, () => {
        const alarmes = desenhaELe(
            ENTRADA_ALARMES,
            { alarms: [{ time: "07:45", enabled: true, mode: 3, custom: mascaraFourPTouch(dia) }] },
            { limit: 3 },
        );

        assert.equal(alarmes.alarms[0].custom, mascaraFourPTouch(dia), nome);
    });
}

/* ---------- o 4P Touch: lembrete de comprimidos ---------- */

const ENTRADA_COMPRIMIDOS = { input: "takePills", key: "take_pills" };

for (const { dia, nome } of DIAS) {
    test(`take_pills escreve a ${nome} na posição certa da máscara`, () => {
        const payload = desenhaELe(
            ENTRADA_COMPRIMIDOS,
            {
                reminderText: "Tomar",
                reminderSettings: [
                    { time: "08:00", enabled: true, frequency: 3, recurrence: { kind: "custom", days: [dia] } },
                ],
            },
            { limit: 3 },
        );

        assert.equal(payload.reminderSettings[0].custom, mascaraFourPTouch(dia), nome);
    });
}

/* ---------- a semana toda, e o dia que ninguém escolheu ---------- */

test("os sete dias marcados de uma vez enchem a máscara do 4P Touch", () => {
    const alarmes = desenhaELe(
        ENTRADA_ALARMES,
        {
            alarms: [{
                time: "07:45",
                enabled: true,
                mode: 3,
                recurrence: { kind: "custom", days: [1, 2, 3, 4, 5, 6, 7] },
            }],
        },
        { limit: 3 },
    );

    assert.equal(alarmes.alarms[0].custom, "1111111");
});

test("a semana de trabalho do 4P Touch deixa o sábado e o domingo a zero", () => {
    const alarmes = desenhaELe(
        ENTRADA_ALARMES,
        {
            alarms: [{
                time: "07:45",
                enabled: true,
                mode: 3,
                recurrence: { kind: "custom", days: [1, 2, 3, 4, 5] },
            }],
        },
        { limit: 3 },
    );

    // Posição 0 é o domingo, posição 6 é o sábado: os dois ficam de fora.
    assert.equal(alarmes.alarms[0].custom, "0111110");
});

test("nenhum dia marcado numa recorrência personalizada é recusado, não gravado a zeros", () => {
    for (const [entrada, desired] of [
        [ENTRADA_ALARMES, { alarms: [{ time: "07:45", enabled: true, mode: 3, custom: "" }] }],
        [
            ENTRADA_PARTILHADA,
            { items: [{ time: "08:30", enabled: true, recurrence: { kind: "custom", days: [] } }] },
        ],
    ]) {
        assert.throws(() => desenhaELe(entrada, desired, { limit: 3 }), /pelo menos um dia/i);
    }
});
