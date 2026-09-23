import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { syncDeviceModalCommandStates } =
    await import("../../src/Dashboard/dashboard/devices/config/panel.js");

/**
 * O que o stream entrega não é só desenho: o `syncDeviceModalCommandStates` escreve o estado
 * de entrega de cada comando no `configurationSync`, e o painel desenha-o a partir daí quando
 * é aberto.
 *
 * O que se afirma aqui é o efeito no estado. Quem decide chamar isto -- o `onCommandsUpdated`
 * do `app.js` -- não é exercitado: mora na raiz de composição e não se levanta sem o
 * documento inteiro.
 */
const deliveryFor = (status, operationId) => ({
    status,
    operations: [{ operationId, confirmationMode: "ack" }],
});

beforeEach(() => {
    state.deviceModal.imei = "868705080304889";
    state.deviceModal.configurationSync = {
        entries: {
            health: { step_goal: deliveryFor("queued", "op-1") },
        },
    };
});

const currentStatus = () =>
    state.deviceModal.configurationSync.entries.health.step_goal.status;

test("um comando confirmado pelo aparelho muda o estado guardado", () => {
    syncDeviceModalCommandStates("868705080304889", [{ id: "op-1", status: "acked" }]);

    assert.notEqual(currentStatus(), "queued", "o estado tem de acompanhar o comando");
});

/** A mensagem de outro dispositivo não pode mexer no que está no modal. */
test("um comando de outro dispositivo não toca no estado", () => {
    syncDeviceModalCommandStates("000000000000000", [{ id: "op-1", status: "acked" }]);

    assert.equal(currentStatus(), "queued");
});

test("um comando que não corresponde a nenhuma operação deixa tudo como está", () => {
    syncDeviceModalCommandStates("868705080304889", [{ id: "op-desconhecida", status: "acked" }]);

    assert.equal(currentStatus(), "queued");
});
