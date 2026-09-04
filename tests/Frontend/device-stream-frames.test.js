import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";

/**
 * O stream do dispositivo lido a bytes, e não por `EventSource`.
 *
 * O `EventSource` não deixa pôr cabeçalhos, e era só por isso que existia um bilhete de uso
 * único no URL. Com `fetch` a credencial vai no `Authorization` como em todas as outras
 * chamadas da dashboard -- mas o preço é que o corte do corpo em frames passa a ser nosso.
 *
 * É aí que mora o defeito provável, e é isso que estes casos prendem. O `snapshot` traz até
 * cem entradas de telemetria e cem de eventos, pelo que **chegar partido entre dois chunks é
 * o caso normal, não a excepção**. Um `split("\n\n")` ingénuo perde o pedaço final e o ecrã
 * fica com o histórico truncado, sem erro à vista.
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
const pedidos = [];
let proximosChunks = [];
let proximaResposta = { ok: true, status: 200 };

globalThis.fetch = async (url, options = {}) => {
    pedidos.push({ url: String(url), options });

    if (!proximaResposta.ok) {
        return {
            ok: false,
            status: proximaResposta.status,
            headers: { get: () => "application/json" },
            text: async () => JSON.stringify({ error: { code: "recusado" } }),
        };
    }

    const chunks = [...proximosChunks];

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
const drena = async () => {
    for (let i = 0; i < 12; i++) {
        await tick();
    }
};

const { state, setSelectedDetail } = await import("../../src/Dashboard/dashboard/state.js");
const stream = await import("../../src/Dashboard/dashboard/devices/stream.js");

const row = (seq) => ({ seq, type: "heart_rate", value: 60 + seq });

const abre = async (imei, chunks) => {
    pedidos.length = 0;
    scheduled.length = 0;
    proximosChunks = chunks;
    proximaResposta = { ok: true, status: 200 };
    document.body.dataset.dashboardAuthRequired = "true";
    window.hubDashboardApiToken = { access_token: "token-de-acesso" };
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei } });
    stream.connectDeviceStream(imei);
    await drena();
};

const frame = (nome, dados) => `event: ${nome}\ndata: ${JSON.stringify(dados)}\n\n`;

test("um frame partido entre dois chunks chega inteiro", async () => {
    const snapshot = frame("snapshot", {
        telemetry: [row(3), row(2), row(1)],
        events: [],
        commands: [],
        limit: 100,
    });
    // O corte cai a meio do JSON, que é o que acontece com um snapshot de cem entradas.
    const corte = Math.floor(snapshot.length / 2);

    await abre("111", [snapshot.slice(0, corte), snapshot.slice(corte)]);

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

    await abre("222", [snapshot + update]);

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

    await abre("333", [": keep-alive\n\n", snapshot, ": keep-alive\n\n"]);

    // O que se prende é que os comentários não viram entregas: se contassem, o
    // `handleStreamUpdate` levava um `JSON.parse("")` e o snapshot não chegava.
    assert.deepEqual(state.selectedDetail.recent.telemetry, [row(1)]);
});

test("a credencial vai no cabeçalho, e nada vai no URL", async () => {
    await abre("444", [frame("snapshot", { telemetry: [], events: [], commands: [], limit: 100 })]);

    const pedido = pedidos.at(-1);
    assert.match(pedido.url, /\/api\/devices\/444\/stream$/, "o URL não devia levar credencial");
    assert.equal(pedido.options.headers.Authorization, "Bearer token-de-acesso");
    assert.equal(
        pedidos.filter((p) => p.url.includes("stream-ticket")).length,
        0,
        "não se pede bilhete nenhum",
    );
});

test("um stream recusado agenda uma religação", async () => {
    pedidos.length = 0;
    scheduled.length = 0;
    proximaResposta = { ok: false, status: 503 };
    document.body.dataset.dashboardAuthRequired = "true";
    window.hubDashboardApiToken = { access_token: "token-de-acesso" };
    stream.initDeviceStream({ renderSelection: () => {} });
    setSelectedDetail({ device: { imei: "555" } });
    stream.connectDeviceStream("555");
    await drena();

    assert.equal(stream.isDeviceStreamLive(), false);
    assert.ok(
        scheduled.some((entry) => !entry.cancelled),
        "um 503 devia agendar uma nova tentativa",
    );
});

test("o fim do corpo agenda uma religação", async () => {
    await abre("666", [frame("snapshot", { telemetry: [], events: [], commands: [], limit: 100 })]);

    // O corpo fechou-se sozinho: o servidor desligou, e a dashboard não pode ficar parada.
    assert.ok(
        scheduled.some((entry) => !entry.cancelled),
        "o servidor a fechar o stream devia agendar uma nova tentativa",
    );
});
