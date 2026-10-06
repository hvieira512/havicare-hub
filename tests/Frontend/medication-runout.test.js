import test from "node:test";
import assert from "node:assert/strict";

import { runoutAt, runoutLabel } from "../../src/Dashboard/dashboard/devices/medication-runout.js";

// Quinta, 1 de outubro de 2026, depois dos dois alarmes do dia já terem tocado.
const now = new Date(2026, 9, 1, 10, 52);
const plans = [
    { times: [{ time: "10:47", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
    { times: [{ time: "10:50", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
];

test("a data sai de percorrer os alarmes do plano, e não de uma divisão", () => {
    // Três por dispensar, dois alarmes por dia: amanhã às 10:47, amanhã às 10:50, e a
    // terceira no dia seguinte.
    assert.deepEqual(runoutAt({ doses: 3, plans, now }), new Date(2026, 9, 3, 10, 47));
});

test("os alarmes que ainda faltam hoje contam antes de se passar a amanhã", () => {
    const manha = new Date(2026, 9, 1, 8, 0);

    assert.deepEqual(runoutAt({ doses: 1, plans, now: manha }), new Date(2026, 9, 1, 10, 47));
    assert.deepEqual(runoutAt({ doses: 3, plans, now: manha }), new Date(2026, 9, 2, 10, 47));
});

test("um alarme à hora exacta já não conta para o dia de hoje", () => {
    const emCima = new Date(2026, 9, 1, 10, 47);

    assert.deepEqual(runoutAt({ doses: 1, plans, now: emCima }), new Date(2026, 9, 1, 10, 50));
});

test("sem plano não há ritmo, e sem ritmo não há data", () => {
    assert.equal(runoutAt({ doses: 3, plans: [], now }), null);
    assert.equal(runoutAt({ doses: 3, plans: undefined, now }), null);
});

test("sem doses por dispensar não há data nenhuma para dar", () => {
    assert.equal(runoutAt({ doses: 0, plans, now }), null);
    assert.equal(runoutAt({ doses: null, plans, now }), null);
});

/** O `0x100A` suspende o plano fora do período: esses alarmes nunca chegam a tocar. */
test("um período que acaba antes das doses não deixa data nenhuma", () => {
    const period = { enabled: true, startDate: "2026-10-01", endDate: "2026-10-02" };

    assert.equal(runoutAt({ doses: 3, plans, period, now }), null);
    assert.deepEqual(runoutAt({ doses: 2, plans, period, now }), new Date(2026, 9, 2, 10, 50));
});

test("um período desligado não limita nada", () => {
    const period = { enabled: false, startDate: "2026-10-01", endDate: "2026-10-02" };

    assert.deepEqual(runoutAt({ doses: 3, plans, period, now }), new Date(2026, 9, 3, 10, 47));
});

test("um plano que ainda não começou conta a partir do início do período", () => {
    const period = { enabled: true, startDate: "2026-10-05", endDate: "2026-12-31" };

    assert.deepEqual(runoutAt({ doses: 1, plans, period, now }), new Date(2026, 9, 5, 10, 47));
});

test("os alarmes desligados do plano não gastam compartimentos", () => {
    const comDesligado = [
        { times: [{ time: "10:47", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
        { times: [{ time: "10:50", enabled: false, slot: 2, recurrence: { kind: "daily" } }] },
    ];

    assert.deepEqual(runoutAt({ doses: 2, plans: comDesligado, now }), new Date(2026, 9, 3, 10, 47));
});

test("a etiqueta é em português e trata hoje e amanhã por esses nomes", () => {
    assert.equal(runoutLabel(new Date(2026, 9, 1, 18, 30), now), "hoje às 18:30");
    assert.equal(runoutLabel(new Date(2026, 9, 2, 10, 47), now), "amanhã às 10:47");
    assert.equal(runoutLabel(new Date(2026, 9, 3, 10, 47), now), "sáb., 3 out. às 10:47");
    assert.equal(runoutLabel(new Date(2026, 10, 14, 9, 5), now), "sáb., 14 nov. às 09:05");
    assert.equal(runoutLabel(null, now), "");
});

test("uma data no passado diz que já acabou", () => {
    assert.equal(runoutLabel(new Date(2026, 8, 30, 10, 50), now), "ontem às 10:50");
    assert.equal(runoutLabel(new Date(2026, 8, 28, 10, 50), now), "seg., 28 set. às 10:50");
});
