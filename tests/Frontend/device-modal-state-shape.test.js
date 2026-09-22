import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { blankDeviceModal, state } from "../../src/Dashboard/dashboard/state.js";

/**
 * O `editDevice` substitui o `state.deviceModal` inteiro. Enquanto escrevia o literal à mão
 * faltava-lhe o `actionDeliveries`, e o `panel.js` escreve nele sem guarda: disparar uma
 * acção num aparelho aberto para edição rebentava com um `TypeError`.
 */
test("a forma em branco traz todas as chaves do estado inicial", () => {
    assert.deepEqual(
        Object.keys(blankDeviceModal()).sort(),
        Object.keys(state.deviceModal).sort(),
    );
});

test("o `actionDeliveries` é um mapa em que se pode escrever", () => {
    const fresh = blankDeviceModal({ mode: "edit" });

    fresh.actionDeliveries.heart_rate = { status: "sent", error: "" };

    assert.deepEqual(fresh.actionDeliveries, { heart_rate: { status: "sent", error: "" } });
});

test("cada chamada traz mapas próprios, e não os do estado a correr", () => {
    const first = blankDeviceModal();
    const second = blankDeviceModal();

    first.actionDeliveries.x = 1;
    first.configurationSync.entries.y = 2;

    assert.deepEqual(second.actionDeliveries, {});
    assert.deepEqual(second.configurationSync.entries, {});
    assert.deepEqual(state.deviceModal.actionDeliveries, {});
});

test("o que o chamador traz ganha ao valor em branco", () => {
    const edited = blankDeviceModal({ mode: "edit", imei: "861265061009822", loading: true });

    assert.equal(edited.mode, "edit");
    assert.equal(edited.imei, "861265061009822");
    assert.equal(edited.loading, true);
    assert.equal(edited.deviceType, "watch");
});
