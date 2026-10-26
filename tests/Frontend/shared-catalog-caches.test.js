import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

// Sem temporizadores reais: o `http.js` agenda um refresh de token só por ser importado, e
// um timer de uma hora deixaria o processo de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const { ensureLicensesLoaded, invalidateLicenses } =
    await import("../../src/Dashboard/dashboard/core/licenses.js");
const { ensureCapabilityCatalog } =
    await import("../../src/Dashboard/dashboard/core/capability-catalog.js");
const { state } = await import("../../src/Dashboard/dashboard/core/state.js");

/**
 * As licenças e o catálogo de capacidades são as caches partilhadas: guardam o que chegou,
 * juntam pedidos simultâneos num só, e nunca põem um erro em cache.
 */

const httpResponse = (status, raw) => new Response(raw, { status });

let calls = [];
let nextResponse = () => httpResponse(200, JSON.stringify({ data: [] }));
let gate = null;
let openGate = () => {};

globalThis.fetch = async (url) => {
    calls.push(String(url));
    if (gate) {
        await gate;
    }
    return nextResponse();
};

/** Segura as respostas até o teste as largar, para haver dois pedidos verdadeiramente juntos. */
const holdResponses = () => {
    gate = new Promise((resolve) => {
        openGate = resolve;
    });
};

const respondWith = (status, body) => {
    nextResponse = () => httpResponse(status, body);
};

const ok = (data) => respondWith(200, JSON.stringify({ data }));

beforeEach(() => {
    calls = [];
    gate = null;
    invalidateLicenses();
    for (const key of Object.keys(state.capabilityCatalogByType)) {
        delete state.capabilityCatalogByType[key];
    }
    ok([]);
});

test("two columns asking for licences at once make one network trip", async () => {
    ok([{ id: 1, name: "Lar do Sol" }]);
    holdResponses();

    const both = Promise.all([ensureLicensesLoaded(), ensureLicensesLoaded()]);
    openGate();
    const [first, second] = await both;

    assert.equal(calls.length, 1, "a promessa em voo é partilhada");
    assert.deepEqual(first, [{ id: 1, name: "Lar do Sol" }]);
    assert.equal(second, first, "as duas colunas ficam com a mesma lista");
});

test("loaded licences do not go back to the network", async () => {
    ok([{ id: 1 }]);
    await ensureLicensesLoaded();
    await ensureLicensesLoaded();

    assert.equal(calls.length, 1);
});

test("a licence load error is not cached", async () => {
    respondWith(500, JSON.stringify({ error: { code: "boom" } }));
    assert.equal(await ensureLicensesLoaded(), null, "quem chama distingue a falha de «não há»");

    ok([{ id: 7 }]);
    assert.deepEqual(await ensureLicensesLoaded(), [{ id: 7 }], "o pedido seguinte volta a tentar");
    assert.equal(calls.length, 2);
});

test("a licence error without a body does not poison the cache either", async () => {
    // Um `500` sem corpo não pode ler-se como sucesso: um `response.data || []` grava a lista
    // vazia para o resto da sessão, e nenhum ecrã volta a ver uma licença.
    respondWith(500, "");
    assert.equal(await ensureLicensesLoaded(), null);
    assert.deepEqual(state.licenses, [], "nada ficou guardado");

    ok([{ id: 9 }]);
    assert.deepEqual(await ensureLicensesLoaded(), [{ id: 9 }]);
    assert.equal(calls.length, 2);
});

test("two sections asking for the same type make one network trip", async () => {
    ok([{ key: "heart_rate" }]);
    holdResponses();

    const both = Promise.all([
        ensureCapabilityCatalog("watch"),
        ensureCapabilityCatalog("watch"),
    ]);
    openGate();
    const [first, second] = await both;

    assert.equal(calls.length, 1, "um pedido por tipo, não um por quem pergunta");
    assert.deepEqual(first, [{ key: "heart_rate" }]);
    assert.equal(second, first);
});

test("each device type has its own cache", async () => {
    ok([{ key: "heart_rate" }]);
    await ensureCapabilityCatalog("watch");
    await ensureCapabilityCatalog("radar");
    await ensureCapabilityCatalog("watch");

    assert.equal(calls.length, 2, "o relógio e o radar são dois catálogos, e cada um vai uma vez");
});

test("a capability catalog error is not cached", async () => {
    respondWith(500, JSON.stringify({ error: { code: "boom" } }));
    assert.deepEqual(await ensureCapabilityCatalog("watch"), []);

    ok([{ key: "spo2" }]);
    assert.deepEqual(await ensureCapabilityCatalog("watch"), [{ key: "spo2" }]);
    assert.equal(calls.length, 2, "o pedido seguinte volta a tentar");
});

test("a capability error without a body does not poison the cache either", async () => {
    respondWith(500, "");
    assert.deepEqual(await ensureCapabilityCatalog("watch"), []);
    assert.equal(
        state.capabilityCatalogByType.watch,
        undefined,
        "uma lista vazia em cache deixava o catálogo do relógio vazio até fechar a aba",
    );

    ok([{ key: "spo2" }]);
    assert.deepEqual(await ensureCapabilityCatalog("watch"), [{ key: "spo2" }]);
    assert.equal(calls.length, 2);
});
