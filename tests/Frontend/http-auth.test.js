import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// Sem temporizadores reais: o refresh de token agenda com `window.setTimeout`, e um timer de
// uma hora deixaria o processo de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const {
    authHeaders,
    setDashboardApiToken,
    clearDashboardApiToken,
    getDashboardApiToken,
    requestJson,
} = await import("../../src/Dashboard/dashboard/api/http.js");

const jsonResponse = (status, body) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify(body),
    headers: { get: () => "application/json" },
});

test("requestJson serializa o query como chave[] e ignora nulos e vazios", async () => {
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

test("authHeaders traz o Bearer só quando há token", () => {
    clearDashboardApiToken();
    assert.deepEqual(authHeaders(), {});

    setDashboardApiToken({ access_token: "abc" });
    assert.deepEqual(authHeaders(), { Authorization: "Bearer abc" });

    clearDashboardApiToken();
});

test("um 401 dispara o refresh e repete o pedido com o token novo", async () => {
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
test("uma renovação que chega depois do logout não repõe o token", async () => {
    setDashboardApiToken({ access_token: "velho", expires_at: "2099-01-01T00:00:00Z" });

    let releaseLogin = () => {};
    const heldLogin = () => new Promise((resolve) => {
        releaseLogin = () => resolve(jsonResponse(200, {
            token: { access_token: "novo", expires_at: "2099-01-01T00:00:00Z" },
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
