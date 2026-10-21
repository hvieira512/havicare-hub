import test from "node:test";
import assert from "node:assert/strict";
import { installStreamHarness } from "./support/device-stream-harness.js";

/**
 * O corte em frames é nosso: com até cem entradas no `snapshot`, chegar partido entre dois
 * chunks é o caso normal.
 */

const harness = installStreamHarness();

const { state, setSelectedDetail } = await import("../../src/Dashboard/dashboard/state.js");
const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");

const row = (seq) => ({ seq, type: "heart_rate", value: 60 + seq });

/** Abre o stream e empurra cada chunk numa leitura à parte. */
const openStream = async (imei, chunks) => {
    harness.reset();
    delete document.body.dataset.dashboardAuthRequired;
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei } });
    stream.connectDeviceStream(imei);
    await harness.settle();
    for (const chunk of chunks) {
        harness.streams.at(-1).write(chunk);
        await harness.settle();
    }
};

const frame = (name, data) => `event: ${name}\ndata: ${JSON.stringify(data)}\n\n`;

test("a frame split across two chunks arrives whole", async () => {
    const snapshot = frame("snapshot", {
        telemetry: [row(3), row(2), row(1)],
        events: [],
        commands: [],
        limit: 100,
    });
    // O corte cai a meio do JSON, que é o que acontece com um snapshot de cem entradas.
    const splitAt = Math.floor(snapshot.length / 2);

    await openStream("111", [snapshot.slice(0, splitAt), snapshot.slice(splitAt)]);

    assert.deepEqual(
        state.selectedDetail.recent.telemetry,
        [row(3), row(2), row(1)],
        "o snapshot devia chegar inteiro apesar de ter vindo em dois pedaços",
    );
});

test("two frames in the same chunk are both delivered", async () => {
    const snapshot = frame("snapshot", {
        telemetry: [row(1)],
        events: [],
        commands: [],
        limit: 100,
    });
    const update = frame("update", {
        telemetry: [row(2)],
        events: [],
        commands: [],
        limit: 100,
    });

    await openStream("222", [snapshot + update]);

    assert.deepEqual(
        state.selectedDetail.recent.telemetry,
        [row(2), row(1)],
        "a actualização devia empilhar-se à frente do instantâneo",
    );
});

test("keep-alive lines do not count as frames", async () => {
    const snapshot = frame("snapshot", {
        telemetry: [row(1)],
        events: [],
        commands: [],
        limit: 100,
    });

    await openStream("333", [": keep-alive\n\n", snapshot, ": keep-alive\n\n"]);

    // Os comentários não viram entregas: se contassem, o `handleStreamUpdate` levaria um
    // `JSON.parse("")` e o snapshot não chegaria.
    assert.deepEqual(state.selectedDetail.recent.telemetry, [row(1)]);
});

test("a malformed frame is skipped and does not take down the next ones", async () => {
    const snapshot = frame("snapshot", { telemetry: [row(1)], events: [], commands: [], limit: 100 });
    // Um `data:` com JSON inválido -- o que um byte perdido no fio produz.
    const garbage = "event: update\ndata: {isto nao e json\n\n";
    const update = frame("update", { telemetry: [row(2)], events: [], commands: [], limit: 100 });

    await openStream("777", [snapshot + garbage + update]);

    assert.deepEqual(
        state.selectedDetail.recent.telemetry,
        [row(2), row(1)],
        "o frame válido a seguir ao lixo devia na mesma ser aplicado",
    );
});
