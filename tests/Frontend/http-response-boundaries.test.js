import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// Sem temporizadores reais: o refresh de token agenda com window.setTimeout, e um timer de
// uma hora deixava o processo de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const { requestJson, setDashboardApiToken, clearDashboardApiToken } =
    await import("../../src/Dashboard/dashboard/api/http.js");

const response = (status, raw) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => raw,
    headers: { get: () => "application/json" },
});

beforeEach(() => {
    clearDashboardApiToken();
});

/**
 * Todos os chamadores decidem por `if (result?.error)`. Um corpo vazio devolvia só o estado,
 * sem `error`, e por isso um 500 lia-se como sucesso -- o `licenses.js` e o
 * `capability-catalog.js` gravavam a lista vazia em cache e não voltavam a tentar.
 */
test("um erro com corpo vazio traz `error`, e não passa por sucesso", async () => {
    globalThis.fetch = async () => response(500, "");

    const result = await requestJson("/api/devices");

    assert.ok(result.error, "um 500 sem corpo é uma falha");
    assert.equal(result._httpStatus, 500);
});

test("um sucesso com corpo vazio continua a ser sucesso", async () => {
    globalThis.fetch = async () => response(204, "");

    const result = await requestJson("/api/devices/x", { method: "DELETE" });

    assert.equal(result.error, undefined);
    assert.equal(result._httpStatus, 204);
});

/**
 * Um 401 depois de o token ter sido renovado não é um token velho -- é a sessão a acabar.
 * Sem o aviso, a dashboard mostrava um erro genérico e ficava sem pedir autenticação.
 */
test("um 401 que sobrevive ao refresh pede autenticação", async () => {
    setDashboardApiToken({ access_token: "velho", refresh_token: "r1" });
    globalThis.fetch = async (url) =>
        String(url) === "/api/auth/login"
            ? response(200, JSON.stringify({ token: { access_token: "novo", refresh_token: "r2" } }))
            : response(401, JSON.stringify({ error: { code: "unauthorized" } }));

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
test("uma falha de rede fala português, e guarda o texto do browser à parte", async () => {
    globalThis.fetch = async () => {
        throw new Error("Failed to fetch");
    };

    const result = await requestJson("/api/devices");

    assert.equal(result.error.code, "network_error");
    assert.doesNotMatch(result.error.message, /failed to fetch/i);
    assert.match(result.error.message, /servidor/i);
    assert.equal(result.error.detail, "Failed to fetch");
});
