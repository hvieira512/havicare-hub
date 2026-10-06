import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { telemetryActivityRow } =
    await import("../../src/Dashboard/dashboard/devices/detail.js");

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
