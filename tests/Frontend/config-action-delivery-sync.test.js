import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { syncDeviceModalCommandStates } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

/**
 * Uma configuração guarda a entrega em `configurationSync.entries`; uma acção guarda-a em
 * `actionDeliveries`, e esse mapa também acompanha o comando até ao fim.
 */

const IMEI = "869243062262262";

function comAcao(delivery) {
    state.deviceModal.imei = IMEI;
    state.deviceModal.configurationSync = { entries: {} };
    state.deviceModal.actionDeliveries = { dispense_now: delivery };
}

test("uma acção confirmada deixa de dizer que aguarda", () => {
    comAcao({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [{ id: "abc123", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "confirmed");
});

test("uma acção recusada mostra a razão", () => {
    comAcao({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [
        { id: "abc123", status: "failed", lastError: "O aparelho recusou" },
    ]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "failed");
    assert.equal(state.deviceModal.actionDeliveries.dispense_now.error, "O aparelho recusou");
});

test("só a acção do comando é tocada", () => {
    comAcao({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [{ id: "outro", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "awaiting_ack");
});

test("uma mensagem de outro aparelho é ignorada", () => {
    comAcao({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates("861265061009822", [{ id: "abc123", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "awaiting_ack");
});
