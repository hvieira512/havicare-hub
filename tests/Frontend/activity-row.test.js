import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { telemetryActivityRow } =
    await import("../../src/Dashboard/dashboard/devices/detail.js");

/**
 * Há mais para ver quando o renderizador declara um `detailsTitle` (a linha é um resumo) ou
 * quando os detalhes têm mais do que um campo, que na linha vão cortados.
 */
test("uma linha que já diz tudo não abre", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 0, chargingState: "absent" },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.notEqual(row.detail, "", "a linha fechada devia ter detalhes");
    assert.equal(row.expanded, "", "e não devia haver nada por trás deles");
});

test("uma linha sem detalhes não abre", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 80 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.equal(row.expanded, "");
});

test("uma linha com mais do que um campo abre e arruma-os", () => {
    const row = telemetryActivityRow({
        type: "motion",
        data: { magnitudeMg: 120, xMg: 10, yMg: 20, zMg: 30 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.match(row.expanded, /\n/);
    assert.match(row.expanded, /20/);
});

test("uma linha cujo resumo esconde alguma coisa abre", () => {
    const row = telemetryActivityRow({
        type: "presence",
        data: {
            personCount: 2,
            people: [
                { postureType: "lying_down", x: 120, y: 30 },
                { postureType: "standing", x: 10, y: 200 },
            ],
        },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.notEqual(row.expanded, "");
});

/** A gaveta escapa o que recebe, `<br>` incluído. */
test("a gaveta não recebe marcação", () => {
    const row = telemetryActivityRow({
        type: "motion",
        data: { magnitudeMg: 120, xMg: 10, yMg: 20, zMg: 30 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    // Com a gaveta vazia o teste passaria sem provar o escape.
    assert.notEqual(row.expanded, "", "a gaveta devia ter conteúdo para haver o que escapar");
    assert.doesNotMatch(row.expanded, /<br|&lt;/);
});

/**
 * O `seq` do Redis é contado por lista: uma leitura e um alarme podem ter ambos `seq=7`, e com
 * a mesma chave abrir a gaveta de um abriria a do outro.
 */
test("uma leitura e um alarme com o mesmo seq não partilham a chave", () => {
    state.selectedImei = "aaa111";

    const reading = telemetryActivityRow({
        type: "heart_rate",
        seq: 7,
        occurredAt: "2026-10-01T09:00:00Z",
        data: { bpm: 72 },
    });
    const alarm = telemetryActivityRow({
        type: "sos",
        seq: 7,
        occurredAt: "2026-10-01T09:00:00Z",
        data: {},
    });

    assert.notEqual(reading.key, alarm.key);
});

test("a mesma mensagem dá sempre a mesma chave", () => {
    state.selectedImei = "aaa111";
    const payload = () => ({
        type: "heart_rate",
        seq: 7,
        occurredAt: "2026-10-01T09:00:00Z",
        data: { bpm: 72 },
    });

    // Dois objectos distintos com o mesmo conteúdo: a chave sai da mensagem e não da
    // identidade do objecto nem de um contador.
    assert.equal(telemetryActivityRow(payload()).key, telemetryActivityRow(payload()).key);
});
