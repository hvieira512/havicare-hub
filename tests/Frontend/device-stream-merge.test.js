import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";

/**
 * O stream deixou de mandar o histórico inteiro a cada actualização.
 *
 * Um radar publica cerca de vinte mensagens por segundo, e mandar as cem entradas de cada
 * lista quatro vezes por segundo eram dezenas de KB por segundo por separador aberto -- foi
 * essa pressão que encheu o buffer do servidor até rebentar o limite de memória do PHP. Agora
 * o servidor manda só o que entrou desde a última vez, e é aqui que o cliente as junta.
 */

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
        FakeEventSource.instances.push(this);
    }

    addEventListener(type, handler) {
        (this.listeners[type] ||= []).push(handler);
    }

    close() {
        this.readyState = FakeEventSource.CLOSED;
    }

    emit(type, data) {
        this.readyState = FakeEventSource.OPEN;
        for (const handler of this.listeners[type] || []) {
            handler({ type, data: JSON.stringify(data) });
        }
    }
}

globalThis.EventSource = FakeEventSource;
globalThis.fetch = async () => ({
    ok: true,
    status: 200,
    headers: { get: () => "application/json" },
    text: async () => JSON.stringify({ data: { ticket: "bilhete", expires_in: 30 } }),
});

const tick = () => new Promise((resolve) => {
    setTimeout(resolve, 0);
});

const { state, setSelectedDetail } = await import("../../src/Dashboard/dashboard/state.js");
const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");

const row = (seq) => ({ seq, type: "heart_rate", value: 60 + seq });

const open = async (imei) => {
    FakeEventSource.instances.length = 0;
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei } });
    stream.connectDeviceStream(imei);
    await tick();
    return FakeEventSource.instances[0];
};

test("o instantâneo substitui o histórico", async () => {
    const source = await open("111");

    source.emit("snapshot", {
        telemetry: [row(3), row(2), row(1)],
        events: [],
        commands: [],
        limit: 100,
    });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [3, 2, 1],
    );
});

test("uma actualização junta-se à frente em vez de substituir", async () => {
    const source = await open("222");
    source.emit("snapshot", { telemetry: [row(2), row(1)], events: [], commands: [], limit: 100 });

    source.emit("update", { telemetry: [row(4), row(3)], events: [], commands: [], limit: 100 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [4, 3, 2, 1],
        "as mais recentes ficam à frente e as antigas sobrevivem",
    );
});

test("a lista não cresce para além do limite do servidor", async () => {
    const source = await open("333");
    source.emit("snapshot", { telemetry: [row(3), row(2), row(1)], events: [], commands: [], limit: 4 });

    source.emit("update", { telemetry: [row(5), row(4)], events: [], commands: [], limit: 4 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [5, 4, 3, 2],
        "apara-se ao limite, deitando fora as mais velhas",
    );
});

test("uma actualização vazia não apaga o que já lá estava", async () => {
    const source = await open("444");
    source.emit("snapshot", { telemetry: [row(1)], events: [], commands: [], limit: 100 });

    source.emit("update", { telemetry: [], events: [], commands: [], limit: 100 });

    assert.deepEqual(state.selectedDetail.recent.telemetry.map((entry) => entry.seq), [1]);
});

test("os comandos vêm sempre por inteiro e substituem", async () => {
    const source = await open("555");
    source.emit("snapshot", {
        telemetry: [],
        events: [],
        commands: [{ id: "a", status: "waiting" }],
        limit: 100,
    });

    // O mesmo comando, noutro estado: substituir é o que o mantém correcto.
    source.emit("update", {
        telemetry: [],
        events: [],
        commands: [{ id: "a", status: "sent" }],
        limit: 100,
    });

    assert.deepEqual(state.selectedDetail.recent.commands, [{ id: "a", status: "sent" }]);
});

test("religar volta a receber um instantâneo, e ele manda", async () => {
    const source = await open("666");
    source.emit("snapshot", { telemetry: [row(9), row(8)], events: [], commands: [], limit: 100 });

    // Um stream novo começa do zero do lado do servidor, e o primeiro envio é um instantâneo.
    const reopened = await open("666");
    reopened.emit("snapshot", { telemetry: [row(2), row(1)], events: [], commands: [], limit: 100 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [2, 1],
        "o instantâneo é a verdade nova, não se junta ao que estava",
    );
});
