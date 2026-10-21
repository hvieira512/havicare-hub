import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

// Sem temporizadores reais: o refresh de token agenda com `window.setTimeout`, e um timer de
// uma hora deixaria o processo de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const {
    allPages,
    authHeaders,
    clearDashboardApiToken,
    getDashboardApiToken,
    requestJson,
    setDashboardApiToken,
} = await import("../../src/Dashboard/dashboard/api/http.js");

const jsonResponse = (status, body) => Response.json(body, { status });

beforeEach(() => {
    clearDashboardApiToken();
});

test("allPages walks every page of a listing", async () => {
    const asked = [];
    const fetchPage = async (params) => {
        asked.push(params.page);
        return { data: [`row${params.page}`], pagination: { page: params.page, totalPages: 3 } };
    };

    const result = await allPages(fetchPage, { deviceType: "gateway" });

    assert.deepEqual(result.data, ["row1", "row2", "row3"]);
    assert.deepEqual(asked, [1, 2, 3]);
});

test("allPages hands back the first error instead of a short list", async () => {
    const fetchPage = async (params) => (params.page === 1
        ? { data: ["row1"], pagination: { page: 1, totalPages: 2 } }
        : { error: { code: "internal_error" } });

    const result = await allPages(fetchPage);

    assert.equal(result.error.code, "internal_error");
});

/**
 * Todos os chamadores decidem por `if (result?.error)`: sem `error`, um 500 de corpo vazio
 * lê-se como sucesso e a lista vazia fica em cache.
 */
test("an error with an empty body carries `error` and does not pass for success", async () => {
    globalThis.fetch = async () => new Response("", { status: 500 });

    const result = await requestJson("/api/devices");

    assert.ok(result.error, "um 500 sem corpo é uma falha");
    assert.equal(result._httpStatus, 500);
});

test("a success with an empty body is still a success", async () => {
    globalThis.fetch = async () => new Response(null, { status: 204 });

    const result = await requestJson("/api/devices/x", { method: "DELETE" });

    assert.equal(result.error, undefined);
    assert.equal(result._httpStatus, 204);
});

/** Um 401 depois de renovar o token não é um token velho: é a sessão a acabar. */
test("a 401 that survives the refresh asks for authentication", async () => {
    setDashboardApiToken({ access_token: "velho", refresh_token: "r1" });
    globalThis.fetch = async (url) =>
        String(url) === "/api/auth/login"
            ? jsonResponse(200, { token: { access_token: "novo", refresh_token: "r2" } })
            : jsonResponse(401, { error: { code: "unauthorized" } });

    let authPrompts = 0;
    const onAuthRequired = () => {
        authPrompts += 1;
    };
    window.addEventListener("hub-dashboard-auth-required", onAuthRequired);

    await requestJson("/api/devices");
    window.removeEventListener("hub-dashboard-auth-required", onAuthRequired);

    assert.equal(authPrompts, 1, "devia ter pedido autenticação uma vez");
    clearDashboardApiToken();
});

/** A dashboard é toda em português, e esta é a mensagem que mais aparece: a rede a cair. */
test("a network failure carries its code and keeps the browser's text aside", async () => {
    globalThis.fetch = async () => {
        throw new Error("Failed to fetch");
    };

    const result = await requestJson("/api/devices");

    assert.equal(result.error.code, "network_error");
    assert.equal(result.error.message, undefined);
    assert.equal(result.error.detail, "Failed to fetch");
});

test("requestJson serialises the query as key[] and skips nulls and empties", async () => {
    clearDashboardApiToken();
    let calledUrl = "";
    globalThis.fetch = async (url) => {
        calledUrl = String(url);
        return jsonResponse(200, { ok: true });
    };

    await requestJson("/api/x", { query: { a: 1, b: "", c: null, d: ["p", "q"], e: undefined } });

    assert.match(calledUrl, /(?:\?|&)a=1(?:&|$)/);
    assert.match(calledUrl, /d%5B%5D=p&d%5B%5D=q/);
    assert.doesNotMatch(calledUrl, /(?:\?|&)b=/);
    assert.doesNotMatch(calledUrl, /(?:\?|&)c=/);
    assert.doesNotMatch(calledUrl, /(?:\?|&)e=/);
});

test("authHeaders carries the Bearer only when there is a token", () => {
    clearDashboardApiToken();
    assert.deepEqual(authHeaders(), {});

    setDashboardApiToken({ access_token: "abc" });
    assert.deepEqual(authHeaders(), { Authorization: "Bearer abc" });

    clearDashboardApiToken();
});

test("a 401 triggers the refresh and repeats the request with the new token", async () => {
    setDashboardApiToken({ access_token: "velho", refresh_token: "r1" });
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        const auth = options.headers?.Authorization;
        calls.push({ url: String(url), auth });
        if (String(url) === "/api/auth/login") {
            return jsonResponse(200, { token: { access_token: "novo", refresh_token: "r2" } });
        }
        return auth === "Bearer velho"
            ? jsonResponse(401, { error: {} })
            : jsonResponse(200, { ok: true });
    };

    const result = await requestJson("/api/devices");

    assert.equal(result.ok, true);
    assert.ok(calls.some((c) => c.url === "/api/auth/login"), "devia ter feito o refresh");
    assert.ok(
        calls.some((c) => c.url === "/api/devices" && c.auth === "Bearer novo"),
        "devia repetir o pedido com o token novo",
    );
    assert.equal(getDashboardApiToken().access_token, "novo");

    clearDashboardApiToken();
});

/** Uma renovação no ar ao sair reacenderia o stream com o ecrã de entrada à frente. */
test("a refresh that lands after logout does not restore the token", async () => {
    setDashboardApiToken({ access_token: "velho", expiresAt: "2099-01-01T00:00:00Z" });

    let releaseLogin = () => {};
    const heldLogin = () => new Promise((resolve) => {
        releaseLogin = () => resolve(jsonResponse(200, {
            token: { access_token: "novo", expiresAt: "2099-01-01T00:00:00Z" },
        }));
    });
    globalThis.fetch = (url) => String(url).includes("/api/auth/login")
        ? heldLogin()
        : Promise.resolve(jsonResponse(401, { error: { code: "unauthorized" } }));

    const pending = requestJson("/api/x");
    await new Promise((resolve) => setTimeout(resolve, 0));

    clearDashboardApiToken();
    releaseLogin();
    await pending;

    assert.equal(getDashboardApiToken(), null);
});
