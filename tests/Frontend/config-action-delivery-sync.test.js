import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { syncDeviceModalCommandStates } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

/**
 * A pastilha de uma acção tem de acompanhar o comando até ao fim.
 *
 * Uma configuração guarda o seu estado de entrega em `configurationSync.entries`, e é isso que
 * o stream sincroniza quando o aparelho responde. Uma **acção** — «Dispensar agora», «Calibrar
 * relógio», os pedidos de parâmetros — guarda-o noutro sítio, em `actionDeliveries`, e esse
 * mapa não era tocado por ninguém.
 *
 * O resultado via-se no ecrã: o comando fechava como confirmado do lado do servidor e o modal
 * continuava a dizer «A aguardar — o valor foi enviado e aguarda resposta do dispositivo»,
 * indefinidamente, até alguém fechar e reabrir o modal.
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

/** Um comando de outra acção não mexe nesta. */
test("só a acção do comando é tocada", () => {
    comAcao({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates(IMEI, [{ id: "outro", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "awaiting_ack");
});

/** E uma mensagem de outro aparelho não mexe em nada. */
test("uma mensagem de outro aparelho é ignorada", () => {
    comAcao({ id: "abc123", status: "awaiting_ack", error: "" });

    syncDeviceModalCommandStates("861265061009822", [{ id: "abc123", status: "acked" }]);

    assert.equal(state.deviceModal.actionDeliveries.dispense_now.status, "awaiting_ack");
});
