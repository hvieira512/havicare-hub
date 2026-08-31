import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";

/**
 * O stream do dispositivo é o único caminho por onde a telemetria, os eventos e os comandos
 * chegam: o `GET /api/devices/{imei}` traz o dispositivo, o modelo e a configuração, e a
 * sondagem de 30 em 30 segundos preserva de propósito o `recent` que já tinha.
 *
 * Antes, um `EventSource` que fechasse fazia `close()` e ficava por ali. O resultado era o
 * histórico congelado no ecrã, sem erro nenhum à vista, até alguém trocar de dispositivo ou
 * o token ser renovado. Estes testes prendem a religação.
 */

// O jsdom não traz `EventSource`, e por isso instala-se um que a instrumentação consegue
// conduzir: abrir, entregar, e fechar como o browser fecha.
class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;
    static instances = [];

    constructor(url) {
        this.url = String(url);
        this.readyState = FakeEventSource.CONNECTING;
        this.listeners = {};
        this.onerror = null;
        this.closed = false;
        FakeEventSource.instances.push(this);
    }

    addEventListener(type, handler) {
        (this.listeners[type] ||= []).push(handler);
    }

    close() {
        this.closed = true;
        this.readyState = FakeEventSource.CLOSED;
    }

    emit(type, data) {
        this.readyState = FakeEventSource.OPEN;
        for (const handler of this.listeners[type] || []) {
            handler(data === undefined ? {} : { data: JSON.stringify(data) });
        }
    }

    /** Como o browser desiste: o estado passa a terminal e só depois é que o erro sai. */
    fail() {
        this.readyState = FakeEventSource.CLOSED;
        this.onerror?.();
    }
}

// O módulo agenda com `window.setTimeout`, e por isso é essa a função que se substitui --
// os temporizadores do node não lhe tocam.
const scheduled = [];
window.setTimeout = (callback, delay) => {
    scheduled.push({ callback, delay });
    return scheduled.length;
};
window.clearTimeout = (handle) => {
    const entry = scheduled[handle - 1];
    if (entry) entry.cancelled = true;
};

globalThis.EventSource = FakeEventSource;

const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");

const reset = () => {
    FakeEventSource.instances.length = 0;
    scheduled.length = 0;
    delete document.body.dataset.dashboardAuthRequired;
    window.hubDashboardApiToken = null;
    stream.initDeviceStream({ renderSelection: () => {} });
    stream.disconnectDeviceStream();
    FakeEventSource.instances.length = 0;
    scheduled.length = 0;
};

/** Corre o último temporizador por correr, como o relógio faria. */
const runPendingTimer = () => {
    const pending = scheduled.filter((entry) => !entry.cancelled && !entry.done);
    const entry = pending[pending.length - 1];
    assert.ok(entry, "esperava-se uma religação agendada");
    entry.done = true;
    entry.callback();
    return entry;
};

test("um stream aberto conta como vivo", () => {
    reset();
    stream.connectDeviceStream("111");
    assert.equal(FakeEventSource.instances.length, 1);
    FakeEventSource.instances[0].emit("open");
    assert.equal(stream.isDeviceStreamLive(), true);
});

test("um stream fechado religa-se em vez de desistir", () => {
    reset();
    stream.connectDeviceStream("222");
    FakeEventSource.instances[0].emit("open");
    FakeEventSource.instances[0].fail();

    assert.equal(stream.isDeviceStreamLive(), false, "fechado não é vivo");
    const timer = runPendingTimer();
    assert.equal(timer.delay, 1000, "a primeira tentativa é ao fim de um segundo");
    assert.equal(FakeEventSource.instances.length, 2, "abriu-se um stream novo");
    assert.ok(
        FakeEventSource.instances[1].url.includes("222"),
        "religou-se ao mesmo dispositivo",
    );
});

test("falhas seguidas afastam as tentativas", () => {
    reset();
    stream.connectDeviceStream("333");
    FakeEventSource.instances[0].fail();
    assert.equal(runPendingTimer().delay, 1000);

    FakeEventSource.instances[1].fail();
    assert.equal(runPendingTimer().delay, 2000);

    FakeEventSource.instances[2].fail();
    assert.equal(runPendingTimer().delay, 4000);
});

test("uma entrega bem sucedida volta a pôr a espera no início", () => {
    reset();
    stream.connectDeviceStream("444");
    FakeEventSource.instances[0].fail();
    runPendingTimer();

    FakeEventSource.instances[1].emit("open");
    FakeEventSource.instances[1].fail();
    assert.equal(runPendingTimer().delay, 1000, "a espera acumulada não se aplica a uma ligação que chegou a servir");
});

test("largar o dispositivo não deixa religação pendente", () => {
    reset();
    stream.connectDeviceStream("555");
    stream.disconnectDeviceStream();
    FakeEventSource.instances[0].fail();

    const pending = scheduled.filter((entry) => !entry.cancelled && !entry.done);
    assert.deepEqual(pending, [], "sem dispositivo escolhido não há nada a religar");
});

/** O `document.hidden` do jsdom é só de leitura, e por isso substitui-se a propriedade. */
const setHidden = (hidden) => {
    Object.defineProperty(document, "hidden", {
        value: hidden,
        configurable: true,
    });
    document.dispatchEvent(new window.Event("visibilitychange"));
};

test("esconder o separador fecha o stream", () => {
    reset();
    stream.connectDeviceStream("777");
    FakeEventSource.instances[0].emit("open");
    assert.equal(stream.isDeviceStreamLive(), true);

    setHidden(true);

    assert.equal(FakeEventSource.instances[0].closed, true, "o stream tem de fechar");
    assert.equal(stream.isDeviceStreamLive(), false);
    const pending = scheduled.filter((entry) => !entry.cancelled && !entry.done);
    assert.deepEqual(pending, [], "esconder não é falhar: não se agenda religação");
    setHidden(false);
});

test("voltar ao separador religa ao mesmo dispositivo", () => {
    reset();
    stream.connectDeviceStream("888");
    FakeEventSource.instances[0].emit("open");
    setHidden(true);
    setHidden(false);

    assert.equal(FakeEventSource.instances.length, 2, "abriu-se um stream novo");
    assert.ok(
        FakeEventSource.instances[1].url.includes("888"),
        "religou-se ao mesmo dispositivo",
    );
});

test("sem dispositivo escolhido a visibilidade não abre nada", () => {
    reset();
    setHidden(false);
    assert.deepEqual(FakeEventSource.instances, []);
    setHidden(true);
    assert.deepEqual(FakeEventSource.instances, []);
    setHidden(false);
});

test("sem credencial não se insiste", () => {
    reset();
    document.body.dataset.dashboardAuthRequired = "true";
    window.hubDashboardApiToken = { access_token: "t" };
    stream.connectDeviceStream("666");

    window.hubDashboardApiToken = null;
    FakeEventSource.instances[0].fail();

    const pending = scheduled.filter((entry) => !entry.cancelled && !entry.done);
    assert.deepEqual(pending, [], "sem token a religação só produzia 401 em série");
});
