import test from "node:test";
import assert from "node:assert/strict";
import { installStreamHarness } from "./support/device-stream-harness.js";

/**
 * O servidor manda só o que entrou desde a última vez, e é aqui que o cliente junta: um radar
 * publica cerca de vinte mensagens por segundo.
 */

const harness = installStreamHarness();

const { state, setSelectedDetail } = await import("../../src/Dashboard/dashboard/core/state.js");
const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");
const { setDashboardApiToken } = await import("../../src/Dashboard/dashboard/api/http.js");

const row = (seq) => ({ seq, type: "heart_rate", value: 60 + seq });

/** Liga e devolve o stream aberto, para os testes lhe empurrarem frames. */
const open = async (imei) => {
    harness.reset();
    setDashboardApiToken({ access_token: "token-de-acesso" });
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei } });
    stream.connectDeviceStream(imei);
    await harness.settle();

    return harness.streams.at(-1);
};

/** Empurra um frame e espera que o cliente o leia. */
const emit = async (source, type, data) => {
    source.emit(type, data);
    await harness.settle();
};

test("the snapshot replaces the history", async () => {
    const source = await open("111");

    await emit(source, "snapshot", {
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

test("an update is prepended instead of replacing", async () => {
    const source = await open("222");
    await emit(source, "snapshot", { telemetry: [row(2), row(1)], events: [], commands: [], limit: 100 });

    await emit(source, "update", { telemetry: [row(4), row(3)], events: [], commands: [], limit: 100 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [4, 3, 2, 1],
        "as mais recentes ficam à frente e as antigas sobrevivem",
    );
});

test("the list does not grow beyond the server limit", async () => {
    const source = await open("333");
    await emit(source, "snapshot", { telemetry: [row(3), row(2), row(1)], events: [], commands: [], limit: 4 });

    await emit(source, "update", { telemetry: [row(5), row(4)], events: [], commands: [], limit: 4 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [5, 4, 3, 2],
        "apara-se ao limite, deitando fora as mais velhas",
    );
});

test("an empty update does not erase what was already there", async () => {
    const source = await open("444");
    await emit(source, "snapshot", { telemetry: [row(1)], events: [], commands: [], limit: 100 });

    await emit(source, "update", { telemetry: [], events: [], commands: [], limit: 100 });

    assert.deepEqual(state.selectedDetail.recent.telemetry.map((entry) => entry.seq), [1]);
});

test("commands always come whole and replace", async () => {
    const source = await open("555");
    await emit(source, "snapshot", {
        telemetry: [],
        events: [],
        commands: [{ id: "a", status: "waiting" }],
        limit: 100,
    });

    // O mesmo comando, noutro estado: substituir é o que o mantém correcto.
    await emit(source, "update", {
        telemetry: [],
        events: [],
        commands: [{ id: "a", status: "sent" }],
        limit: 100,
    });

    assert.deepEqual(state.selectedDetail.recent.commands, [{ id: "a", status: "sent" }]);
});

test("reconnecting receives a snapshot again, and it wins", async () => {
    const source = await open("666");
    await emit(source, "snapshot", { telemetry: [row(9), row(8)], events: [], commands: [], limit: 100 });

    // Um stream novo começa do zero do lado do servidor, e o primeiro envio é um instantâneo.
    const reopened = await open("666");
    await emit(reopened, "snapshot", { telemetry: [row(2), row(1)], events: [], commands: [], limit: 100 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [2, 1],
        "o instantâneo é a verdade nova, não se junta ao que estava",
    );
});

test("connections merge like events, in their own list", async () => {
    const source = await open("333");
    await emit(source, "snapshot", {
        telemetry: [],
        events: [],
        connections: [{ seq: 1, type: "device.disconnected" }],
        commands: [],
        limit: 100,
    });

    await emit(source, "update", {
        telemetry: [],
        events: [],
        connections: [{ seq: 2, type: "device.connected" }],
        commands: [],
        limit: 100,
    });

    assert.deepEqual(
        state.selectedDetail.recent.connections.map((entry) => entry.type),
        ["device.connected", "device.disconnected"],
    );
});

test("the latest of each type comes from the server and replaces the previous one", async () => {
    const source = await open("777");
    await emit(source, "snapshot", { telemetry: [], latestTelemetry: [row(1)], events: [], commands: [], limit: 100 });

    await emit(source, "update", { telemetry: [row(2)], latestTelemetry: [row(2)], events: [], commands: [], limit: 100 });

    assert.deepEqual(state.selectedDetail.recent.latestTelemetry.map((entry) => entry.seq), [2]);
});
