import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";
import { installStreamHarness } from "./support/device-stream-harness.js";

/**
 * Um token morto não melhora com tentativas.
 *
 * O stream tratava qualquer resposta que não fosse `ok` da mesma maneira -- religava com
 * recuo exponencial. Com a sessão expirada isso dava uma dashboard a bater à porta para
 * sempre, calada, em vez de pedir autenticação. O 401 e o 403 pedem o contrário: avisar
 * quem trata da sessão e ficar quieto.
 */

const harness = installStreamHarness();

const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");
const { setDashboardApiToken, clearDashboardApiToken } = await import("../../src/Dashboard/dashboard/api/http.js");

/** Conta os pedidos de autenticação emitidos enquanto corre o que lhe for dado. */
const countingAuthRequired = async (run) => {
    let requested = 0;
    const listener = () => {
        requested += 1;
    };
    window.addEventListener("hub-dashboard-auth-required", listener);
    try {
        await run();
    } finally {
        window.removeEventListener("hub-dashboard-auth-required", listener);
    }
    return requested;
};

const reset = () => {
    harness.reset();
    clearDashboardApiToken();
    stream.initDeviceStream({ renderSelection: () => {} });
    stream.disconnectDeviceStream();
    document.body.dataset.dashboardAuthRequired = "true";
    setDashboardApiToken({ access_token: "token-expirado" });
    harness.reset();
};

const pendingTimers = () => harness.scheduled.filter((entry) => !entry.cancelled && !entry.done);

for (const status of [401, 403]) {
    test(`um ${status} pede autenticação em vez de religar`, async () => {
        reset();
        harness.refuseWith(status);

        const requested = await countingAuthRequired(async () => {
            stream.connectDeviceStream("123");
            await harness.settle();
        });

        assert.equal(requested, 1, "quem trata da sessão tem de ser avisado");
        assert.deepEqual(
            pendingTimers(),
            [],
            "com a credencial recusada, religar só repete a recusa",
        );
        assert.equal(stream.isDeviceStreamLive(), false);
    });
}

test("uma recusa que não é de credencial continua a religar sem pedir autenticação", async () => {
    reset();
    // O `503 too_many_streams` é temporário: a credencial serve e vale a pena voltar a tentar.
    harness.refuseWith(503);

    const requested = await countingAuthRequired(async () => {
        stream.connectDeviceStream("456");
        await harness.settle();
    });

    assert.equal(requested, 0);
    assert.equal(pendingTimers().length, 1, "continua agendada uma religação");
});
