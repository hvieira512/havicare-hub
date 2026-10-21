import test from "node:test";
import assert from "node:assert/strict";

import { syncDeviceModalCommandStates } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

/**
 * Uma configuração guarda a entrega em `configurationSync.entries`; uma acção guarda-a em
 * `actionDeliveries`, e os dois acompanham o comando até ao fim, mesmo sem o painel desenhado.
 */

const IMEI = "869243062262262";

function withAction(delivery) {
    state.deviceModal.imei = IMEI;
    state.deviceModal.configurationSync = { entries: {} };
    state.deviceModal.actionDeliveries = { dispense_now: delivery };
}

test("a confirmed action stops saying it is waiting", () => {
    withAction({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [{ id: "abc123", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "confirmed");
});

test("a refused action shows the reason", () => {
    withAction({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [
        { id: "abc123", status: "failed", lastError: "O aparelho recusou" },
    ]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "failed");
    assert.equal(state.deviceModal.actionDeliveries.dispense_now.error, "O aparelho recusou");
});

test("only the action of that command is touched", () => {
    withAction({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [{ id: "outro", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "awaiting_ack");
});

test("a message from another device is ignored", () => {
    withAction({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates("861265061009822", [{ id: "abc123", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "awaiting_ack");
});

const deliveryFor = (status, operationId) => ({
    status,
    operations: [{ operationId, confirmationMode: "ack" }],
});

function withSetting() {
    state.deviceModal.imei = IMEI;
    state.deviceModal.configurationSync = {
        entries: {
            health: { step_goal: deliveryFor("queued", "op-1") },
        },
    };
}

const currentStatus = () =>
    state.deviceModal.configurationSync.entries.health.step_goal.status;

test("a command the device confirmed changes the stored state", () => {
    withSetting();

    syncDeviceModalCommandStates(IMEI, [{ id: "op-1", status: "acked" }]);

    assert.notEqual(currentStatus(), "queued", "o estado tem de acompanhar o comando");
});

test("a command that matches no operation leaves everything as it was", () => {
    withSetting();

    syncDeviceModalCommandStates(IMEI, [{ id: "op-desconhecida", status: "acked" }]);

    assert.equal(currentStatus(), "queued");
});
