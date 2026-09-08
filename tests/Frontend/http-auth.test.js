import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// Sem temporizadores reais: o refresh de token agenda com window.setTimeout, e um timer de
// uma hora deixava o processo de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const {
    withQuery,
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

test("withQuery serializa arrays como chave[] e ignora nulos e vazios", () => {
    const url = withQuery("/api/x", { a: 1, b: "", c: null, d: ["p", "q"], e: undefined });

    assert.match(url, /(?:\?|&)a=1(?:&|$)/);
    assert.match(url, /d%5B%5D=p&d%5B%5D=q/);
    assert.doesNotMatch(url, /(?:\?|&)b=/);
    assert.doesNotMatch(url, /(?:\?|&)c=/);
    assert.doesNotMatch(url, /(?:\?|&)e=/);
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
