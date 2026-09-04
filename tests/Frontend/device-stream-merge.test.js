import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";
import { installStreamHarness } from "./support/device-stream-harness.js";

/**
 * O stream deixou de mandar o histórico inteiro a cada actualização.
 *
 * Um radar publica cerca de vinte mensagens por segundo, e mandar as cem entradas de cada
 * lista quatro vezes por segundo eram dezenas de KB por segundo por separador aberto -- foi
 * essa pressão que encheu o buffer do servidor até rebentar o limite de memória do PHP. Agora
 * o servidor manda só o que entrou desde a última vez, e é aqui que o cliente as junta.
 */

const harness = installStreamHarness();

const { state, setSelectedDetail } = await import("../../src/Dashboard/dashboard/state.js");
const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");

const row = (seq) => ({ seq, type: "heart_rate", value: 60 + seq });

/** Liga e devolve o stream aberto, para os testes lhe empurrarem frames. */
const open = async (imei) => {
    harness.reset();
    window.hubDashboardApiToken = { access_token: "token-de-acesso" };
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

test("o instantâneo substitui o histórico", async () => {
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

test("uma actualização junta-se à frente em vez de substituir", async () => {
    const source = await open("222");
    await emit(source, "snapshot", { telemetry: [row(2), row(1)], events: [], commands: [], limit: 100 });

    await emit(source, "update", { telemetry: [row(4), row(3)], events: [], commands: [], limit: 100 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [4, 3, 2, 1],
        "as mais recentes ficam à frente e as antigas sobrevivem",
    );
});

test("a lista não cresce para além do limite do servidor", async () => {
    const source = await open("333");
    await emit(source, "snapshot", { telemetry: [row(3), row(2), row(1)], events: [], commands: [], limit: 4 });

    await emit(source, "update", { telemetry: [row(5), row(4)], events: [], commands: [], limit: 4 });

    assert.deepEqual(
        state.selectedDetail.recent.telemetry.map((entry) => entry.seq),
        [5, 4, 3, 2],
        "apara-se ao limite, deitando fora as mais velhas",
    );
});

test("uma actualização vazia não apaga o que já lá estava", async () => {
    const source = await open("444");
    await emit(source, "snapshot", { telemetry: [row(1)], events: [], commands: [], limit: 100 });

    await emit(source, "update", { telemetry: [], events: [], commands: [], limit: 100 });

    assert.deepEqual(state.selectedDetail.recent.telemetry.map((entry) => entry.seq), [1]);
});

test("os comandos vêm sempre por inteiro e substituem", async () => {
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

test("religar volta a receber um instantâneo, e ele manda", async () => {
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
