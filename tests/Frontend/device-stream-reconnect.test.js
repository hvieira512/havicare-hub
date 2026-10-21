import test from "node:test";
import assert from "node:assert/strict";
import { installStreamHarness } from "./support/device-stream-harness.js";

/**
 * O stream é o único caminho da telemetria, dos eventos e dos comandos, e sem religar o
 * histórico congela calado; com `fetch`, é o fim do corpo que assinala a queda, com o estado.
 */

const harness = installStreamHarness();

const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");
const { setDashboardApiToken, clearDashboardApiToken } = await import("../../src/Dashboard/dashboard/api/http.js");

const reset = () => {
    harness.reset();
    delete document.body.dataset.dashboardAuthRequired;
    clearDashboardApiToken();
    stream.initDeviceStream({ renderSelection: () => {} });
    stream.disconnectDeviceStream();
    harness.reset();
};

/** Liga e espera que o stream abra, que é assíncrono. */
const connect = async (imei) => {
    stream.connectDeviceStream(imei);
    await harness.settle();
};

/** Um corpo de frame vazio: aqui o que interessa é a entrega ter acontecido, não o conteúdo. */
const frame = () => ({ telemetry: [], events: [], commands: [], limit: 100 });

test("um stream aberto conta como vivo", async () => {
    reset();
    await connect("111");

    assert.equal(harness.streams.length, 1);
    assert.equal(harness.streams[0].closed, false);
});

test("a credencial vai no cabeçalho e nada vai no URL", async () => {
    reset();
    document.body.dataset.dashboardAuthRequired = "true";
    setDashboardApiToken({ access_token: "token-de-uma-hora" });

    await connect("999");

    const request = harness.requests.at(-1);
    assert.equal(request.options.headers.Authorization, "Bearer token-de-uma-hora");
    assert.ok(
        !request.url.includes("token-de-uma-hora"),
        "o token não pode aparecer no URL: fica no registo de qualquer proxy",
    );
});

test("o servidor a fechar o corpo religa em vez de desistir", async () => {
    reset();
    await connect("222");
    harness.streams[0].end();
    await harness.settle();

    const timer = await harness.runPendingTimer();
    assert.equal(timer.delay, 1000, "a primeira tentativa é ao fim de um segundo");
    assert.equal(harness.streams.length, 2, "abriu-se um stream novo");
    assert.ok(harness.requests.at(-1).url.includes("222"), "religou-se ao mesmo dispositivo");
});

test("uma recusa do servidor religa, e o estado dela é visível", async () => {
    reset();
    // O `503 too_many_streams` é temporário, e não se confunde com um 404.
    harness.refuseWith(503);
    await connect("223");

    assert.equal(harness.streams.length, 0, "não se abriu stream nenhum");
    const timer = await harness.runPendingTimer();
    assert.equal(timer.delay, 1000);
});

test("falhas seguidas afastam as tentativas", async () => {
    reset();
    await connect("333");
    harness.streams[0].end();
    await harness.settle();
    assert.equal((await harness.runPendingTimer()).delay, 1000);

    harness.streams[1].end();
    await harness.settle();
    assert.equal((await harness.runPendingTimer()).delay, 2000);

    harness.streams[2].end();
    await harness.settle();
    assert.equal((await harness.runPendingTimer()).delay, 4000);
});

test("uma entrega bem sucedida volta a pôr a espera no início", async () => {
    reset();
    harness.refuseWith(503);
    await connect("444");
    assert.equal((await harness.runPendingTimer()).delay, 1000);

    // A segunda tentativa abre **e entrega**; é a entrega que apaga a espera acumulada, não
    // o simples facto de a ligação ter sido aceite.
    harness.accept();
    await harness.runPendingTimer();
    harness.streams.at(-1).emit("snapshot", frame());
    await harness.settle();
    harness.streams.at(-1).end();
    await harness.settle();

    assert.equal(
        (await harness.runPendingTimer()).delay,
        1000,
        "a espera acumulada não se aplica a uma ligação que chegou a servir",
    );
});

test("largar o dispositivo não deixa religação pendente", async () => {
    reset();
    await connect("555");
    stream.disconnectDeviceStream();
    harness.streams[0].end();
    await harness.settle();

    const pending = harness.scheduled.filter((entry) => !entry.cancelled && !entry.done);
    assert.deepEqual(pending, [], "sem dispositivo escolhido não há nada a religar");
});

test("trocar de dispositivo antes de o stream abrir não abre o errado", async () => {
    reset();
    // Duas ligações sem esperar pela primeira: a resposta da primeira chega depois de o
    // utilizador já ter escolhido outro dispositivo.
    stream.connectDeviceStream("aaa");
    stream.connectDeviceStream("bbb");
    await harness.settle();

    const servindo = harness.streams.filter((entry) => !entry.closed);
    assert.equal(servindo.length, 1, "só a tentativa actual pode ficar a servir");
    assert.ok(harness.requests.at(-1).url.includes("bbb"));
});

/** O `document.hidden` do jsdom é só de leitura, e por isso substitui-se a propriedade. */
const setHidden = (hidden) => {
    Object.defineProperty(document, "hidden", {
        value: hidden,
        configurable: true,
    });
    document.dispatchEvent(new window.Event("visibilitychange"));
};

test("esconder o separador fecha o stream", async () => {
    reset();
    await connect("777");
    assert.equal(harness.streams[0].closed, false);

    setHidden(true);
    await harness.settle();

    assert.equal(harness.streams[0].closed, true);
    const pending = harness.scheduled.filter((entry) => !entry.cancelled && !entry.done);
    assert.deepEqual(pending, [], "esconder não é falhar: não se agenda religação");
    setHidden(false);
    await harness.settle();
});

test("voltar ao separador religa ao mesmo dispositivo", async () => {
    reset();
    await connect("888");
    setHidden(true);
    await harness.settle();
    setHidden(false);
    await harness.settle();

    assert.equal(harness.streams.length, 2, "abriu-se um stream novo");
    assert.ok(harness.requests.at(-1).url.includes("888"), "religou-se ao mesmo dispositivo");
});

test("sem dispositivo escolhido a visibilidade não abre nada", async () => {
    reset();
    setHidden(false);
    await harness.settle();
    assert.deepEqual(harness.streams, []);
    setHidden(true);
    await harness.settle();
    assert.deepEqual(harness.streams, []);
    setHidden(false);
    await harness.settle();
});

test("sem credencial não se insiste", async () => {
    reset();
    document.body.dataset.dashboardAuthRequired = "true";
    setDashboardApiToken({ access_token: "t" });
    await connect("666");

    clearDashboardApiToken();
    harness.streams[0].end();
    await harness.settle();

    const pending = harness.scheduled.filter((entry) => !entry.cancelled && !entry.done);
    assert.deepEqual(pending, [], "sem token a religação só produzia 401 em série");
});

/** Um token morto não melhora com tentativas: o 401 e o 403 avisam a sessão e não religam. */
const resetWithExpiredToken = () => {
    reset();
    document.body.dataset.dashboardAuthRequired = "true";
    setDashboardApiToken({ access_token: "token-expirado" });
};

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

const livePendingTimers = () => harness.scheduled.filter((entry) => !entry.cancelled && !entry.done);

for (const status of [401, 403]) {
    test(`um ${status} pede autenticação em vez de religar`, async () => {
        resetWithExpiredToken();
        harness.refuseWith(status);

        const requested = await countingAuthRequired(async () => {
            stream.connectDeviceStream("123");
            await harness.settle();
        });

        assert.equal(requested, 1, "quem trata da sessão tem de ser avisado");
        assert.deepEqual(
            livePendingTimers(),
            [],
            "com a credencial recusada, religar só repete a recusa",
        );
    });
}
