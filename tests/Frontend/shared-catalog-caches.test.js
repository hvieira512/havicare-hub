import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// Sem temporizadores reais: o `http.js` agenda um refresh de token só por ser importado, e
// um timer de uma hora deixava o processo de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const { ensureLicensesLoaded, invalidateLicenses } =
    await import("../../src/Dashboard/dashboard/licenses.js");
const { ensureCapabilityCatalog } =
    await import("../../src/Dashboard/dashboard/capability-catalog.js");
const { state } = await import("../../src/Dashboard/dashboard/state.js");

/**
 * As licenças e o catálogo de capacidades são as duas caches partilhadas da dashboard -- seis
 * ecrãs pedem-nas -- e não tinham teste nenhum. As três regras que ambas seguem são as
 * mesmas: guardar o que chegou, juntar num só pedido os que forem feitos ao mesmo tempo, e
 * nunca pôr um erro em cache.
 *
 * A última encosta ao `api/http.js`: uma resposta de erro com o corpo vazio tem de trazer
 * `error`, ou o `response.data || []` destas duas guardava a lista vazia para o resto da
 * sessão.
 */

/** Uma resposta do `fetch` tal como o `http.js` a lê: o estado e o corpo em texto. */
const httpResponse = (status, raw) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => raw,
    headers: { get: () => "application/json" },
});

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

/* ---------- licenças ---------- */

test("duas colunas a pedir licenças ao mesmo tempo dão uma só ida à rede", async () => {
    ok([{ id: 1, name: "Lar do Sol" }]);
    holdResponses();

    const both = Promise.all([ensureLicensesLoaded(), ensureLicensesLoaded()]);
    openGate();
    const [first, second] = await both;

    assert.equal(calls.length, 1, "a promessa em voo é partilhada");
    assert.deepEqual(first, [{ id: 1, name: "Lar do Sol" }]);
    assert.equal(second, first, "as duas colunas ficam com a mesma lista");
});

test("licenças já carregadas não voltam à rede", async () => {
    ok([{ id: 1 }]);
    await ensureLicensesLoaded();
    await ensureLicensesLoaded();

    assert.equal(calls.length, 1);
});

test("um erro a carregar licenças não fica em cache", async () => {
    respondWith(500, JSON.stringify({ error: { code: "boom" } }));
    assert.equal(await ensureLicensesLoaded(), null, "quem chama distingue a falha de «não há»");

    ok([{ id: 7 }]);
    assert.deepEqual(await ensureLicensesLoaded(), [{ id: 7 }], "o pedido seguinte volta a tentar");
    assert.equal(calls.length, 2);
});

test("um erro de licenças sem corpo nenhum também não envenena a cache", async () => {
    // O `500` sem corpo lia-se como sucesso, e o `response.data || []` gravava a lista vazia
    // para o resto da sessão: nenhum ecrã voltava a ver uma licença.
    respondWith(500, "");
    assert.equal(await ensureLicensesLoaded(), null);
    assert.deepEqual(state.settingsModal.licenses, [], "nada ficou guardado");

    ok([{ id: 9 }]);
    assert.deepEqual(await ensureLicensesLoaded(), [{ id: 9 }]);
    assert.equal(calls.length, 2);
});

/* ---------- catálogo de capacidades ---------- */

test("duas secções a pedir o mesmo tipo dão uma só ida à rede", async () => {
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

test("cada tipo de dispositivo tem a sua cache", async () => {
    ok([{ key: "heart_rate" }]);
    await ensureCapabilityCatalog("watch");
    await ensureCapabilityCatalog("radar");
    await ensureCapabilityCatalog("watch");

    assert.equal(calls.length, 2, "o relógio e o radar são dois catálogos, e cada um vai uma vez");
});

test("um erro no catálogo de capacidades não fica em cache", async () => {
    respondWith(500, JSON.stringify({ error: { code: "boom" } }));
    assert.deepEqual(await ensureCapabilityCatalog("watch"), []);

    ok([{ key: "spo2" }]);
    assert.deepEqual(await ensureCapabilityCatalog("watch"), [{ key: "spo2" }]);
    assert.equal(calls.length, 2, "o pedido seguinte volta a tentar");
});

test("um erro de capacidades sem corpo nenhum também não envenena a cache", async () => {
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
