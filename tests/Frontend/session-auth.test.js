import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// Sem temporizadores reais: os relógios de inatividade são de minutos e deixavam o processo
// de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const { validAdminToken, initializeDashboardSession } = await import(
    "../../src/Dashboard/dashboard/auth/session.js",
);

/**
 * As guardas que decidem se uma sessão vale, e o arranque que a vai buscar ao cookie.
 *
 * A credencial já não vive no `sessionStorage`, que é por separador: era isso que punha o
 * segundo separador a pedir login com a sessão do primeiro aberta.
 */
const future = () => new Date(Date.now() + 3_600_000).toISOString();
const past = () => new Date(Date.now() - 1_000).toISOString();

const adminToken = (over = {}) => ({
    role: "hub_admin",
    access_token: "a",
    expires_at: future(),
    ...over,
});

test("validAdminToken aceita um admin com token de acesso por expirar", () => {
    assert.equal(validAdminToken(adminToken()), true);
});

test("validAdminToken recusa um papel que não é admin", () => {
    assert.equal(validAdminToken(adminToken({ role: "viewer" })), false);
});

test("validAdminToken recusa um token de acesso já expirado", () => {
    assert.equal(validAdminToken(adminToken({ expires_at: past() })), false);
});

test("validAdminToken recusa tokens em falta ou lixo", () => {
    assert.equal(validAdminToken(adminToken({ access_token: "" })), false);
    assert.equal(validAdminToken(adminToken({ expires_at: "" })), false);
    assert.equal(validAdminToken(null), false);
    assert.equal(validAdminToken("não é objeto"), false);
});

const installDom = () => {
    document.body.dataset.dashboardAuthRequired = "true";
    document.getElementById("dashboardLogin")?.remove();
    document.getElementById("dashboardApp")?.remove();
    document.body.insertAdjacentHTML("beforeend", `
        <div id="dashboardLogin" class="d-none" hidden>
            <form id="dashboardLoginForm">
                <input id="dashboardLoginUsername">
                <input id="dashboardLoginPassword">
                <button id="dashboardLoginSubmit"></button>
            </form>
        </div>
        <div id="dashboardApp" hidden></div>
    `);
};

const captureFetch = (respond) => {
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        calls.push({ url: String(url), options });
        return respond(String(url));
    };
    return calls;
};

const sessionResponse = (status, body) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
});

test("sem sessão no cookie, o arranque mostra o login", async () => {
    installDom();
    const calls = captureFetch(() => sessionResponse(401, { error: { code: "invalid_refresh_token" } }));
    let started = false;

    await initializeDashboardSession(async () => {
        started = true;
    });

    assert.equal(started, false);
    assert.equal(document.getElementById("dashboardApp").hidden, true);
    assert.equal(document.getElementById("dashboardLogin").hidden, false);
    assert.equal(calls[0].url, "/api/auth/login");
});

test("um separador novo entra pelo cookie, sem credencial nenhuma guardada", async () => {
    installDom();
    localStorage.clear();
    sessionStorage.clear();
    const token = adminToken({ access_token: "token-do-separador-novo", username: "admin" });
    const calls = captureFetch(() => sessionResponse(200, { status: "ok", token }));
    let started = false;

    await initializeDashboardSession(async () => {
        started = true;
    });

    assert.equal(started, true, "a dashboard devia arrancar já autenticada");
    assert.equal(document.getElementById("dashboardApp").hidden, false);
    // O corpo vazio é o que diz que a credencial vai no cookie, e não no pedido.
    assert.equal(calls[0].options.body, "{}");
    assert.equal(calls[0].options.credentials, "same-origin");
    // E o token não fica em armazenamento nenhum: morre com o separador.
    assert.equal(sessionStorage.length, 0);
    const stored = Object.keys(localStorage).map((key) => localStorage.getItem(key)).join("|");
    assert.equal(stored.includes(token.access_token), false);
});
