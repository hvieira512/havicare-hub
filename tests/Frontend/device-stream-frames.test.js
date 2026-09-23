import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";

/**
 * O stream do dispositivo lido a bytes, e não por `EventSource`.
 *
 * O `EventSource` não deixa pôr cabeçalhos; com `fetch` a credencial vai no `Authorization`,
 * mas o corte do corpo em frames passa a ser nosso. O `snapshot` traz até cem entradas de
 * cada lado, pelo que **chegar partido entre dois chunks é o caso normal, não a excepção**.
 */

// O módulo agenda com `window.setTimeout`; os temporizadores do node não lhe tocam.
const scheduled = [];
window.setTimeout = (callback, delay) => {
    scheduled.push({ callback, delay });
    return scheduled.length;
};
window.clearTimeout = (handle) => {
    const entry = scheduled[handle - 1];
    if (entry) entry.cancelled = true;
};

/**
 * Um `fetch` que devolve o corpo aos pedaços que lhe derem, para se poder cortar um frame
 * exactamente onde dói.
 */
const requests = [];
let nextChunks = [];
let nextResponse = { ok: true, status: 200 };

globalThis.fetch = async (url, options = {}) => {
    requests.push({ url: String(url), options });

    if (!nextResponse.ok) {
        return {
            ok: false,
            status: nextResponse.status,
            headers: { get: () => "application/json" },
            text: async () => JSON.stringify({ error: { code: "recusado" } }),
        };
    }

    const chunks = [...nextChunks];

    return {
        ok: true,
        status: 200,
        headers: { get: () => "text/event-stream" },
        body: new ReadableStream({
            start(controller) {
                const encoder = new TextEncoder();
                for (const chunk of chunks) {
                    controller.enqueue(encoder.encode(chunk));
                }
                controller.close();
            },
        }),
    };
};

const tick = () => new Promise((resolve) => {
    setTimeout(resolve, 0);
});

/** Deixa correr as leituras do corpo, que são várias microtarefas. */
const drain = async () => {
    for (let i = 0; i < 12; i++) {
        await tick();
    }
};

const { state, setSelectedDetail } = await import("../../src/Dashboard/dashboard/state.js");
const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");
const { setDashboardApiToken } = await import("../../src/Dashboard/dashboard/api/http.js");

const row = (seq) => ({ seq, type: "heart_rate", value: 60 + seq });

const openStream = async (imei, chunks) => {
    requests.length = 0;
    scheduled.length = 0;
    nextChunks = chunks;
    nextResponse = { ok: true, status: 200 };
    document.body.dataset.dashboardAuthRequired = "true";
    setDashboardApiToken({ access_token: "token-de-acesso" });
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei } });
    stream.connectDeviceStream(imei);
    await drain();
};

const frame = (name, data) => `event: ${name}\ndata: ${JSON.stringify(data)}\n\n`;

test("um frame partido entre dois chunks chega inteiro", async () => {
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

test("dois frames no mesmo chunk são ambos entregues", async () => {
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

test("as linhas de keep-alive não contam como frames", async () => {
    const snapshot = frame("snapshot", {
        telemetry: [row(1)],
        events: [],
        commands: [],
        limit: 100,
    });

    await openStream("333", [": keep-alive\n\n", snapshot, ": keep-alive\n\n"]);

    // O que se prende é que os comentários não viram entregas: se contassem, o
    // `handleStreamUpdate` levava um `JSON.parse("")` e o snapshot não chegava.
    assert.deepEqual(state.selectedDetail.recent.telemetry, [row(1)]);
});

test("a credencial vai no cabeçalho, e nada vai no URL", async () => {
    await openStream("444", [frame("snapshot", { telemetry: [], events: [], commands: [], limit: 100 })]);

    const request = requests.at(-1);
    assert.match(request.url, /\/api\/devices\/444\/stream$/, "o URL não devia levar credencial");
    assert.equal(request.options.headers.Authorization, "Bearer token-de-acesso");
    assert.equal(
        requests.filter((p) => p.url.includes("stream-ticket")).length,
        0,
        "não se pede bilhete nenhum",
    );
});

test("um stream recusado agenda uma religação", async () => {
    requests.length = 0;
    scheduled.length = 0;
    nextResponse = { ok: false, status: 503 };
    document.body.dataset.dashboardAuthRequired = "true";
    setDashboardApiToken({ access_token: "token-de-acesso" });
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei: "555" } });
    stream.connectDeviceStream("555");
    await drain();

    assert.equal(stream.isDeviceStreamLive(), false);
    assert.ok(
        scheduled.some((entry) => !entry.cancelled),
        "um 503 devia agendar uma nova tentativa",
    );
});

test("o fim do corpo agenda uma religação", async () => {
    await openStream("666", [frame("snapshot", { telemetry: [], events: [], commands: [], limit: 100 })]);

    // O corpo fechou-se sozinho: o servidor desligou, e a dashboard não pode ficar parada.
    assert.ok(
        scheduled.some((entry) => !entry.cancelled),
        "o servidor a fechar o stream devia agendar uma nova tentativa",
    );
});

test("um frame malformado é saltado e não derruba os seguintes", async () => {
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
