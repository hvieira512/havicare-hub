import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { validAdminToken, restoreToken } from "../../src/Dashboard/dashboard/auth/session.js";

/**
 * As guardas que decidem se uma sessão vale: papel de admin, tokens presentes, refresh por
 * expirar. É o que o CLAUDE.md manda prender, e não tinha teste nenhum.
 */
const TOKEN_KEY = "hub-dashboard-api-token";
const future = () => new Date(Date.now() + 3_600_000).toISOString();
const past = () => new Date(Date.now() - 1_000).toISOString();

const adminToken = (over = {}) => ({
    role: "hub_admin",
    access_token: "a",
    refresh_token: "r",
    refresh_expires_at: future(),
    ...over,
});

test("validAdminToken aceita um admin completo e por expirar", () => {
    assert.equal(validAdminToken(adminToken()), true);
});

test("validAdminToken recusa um papel que não é admin", () => {
    assert.equal(validAdminToken(adminToken({ role: "viewer" })), false);
});

test("validAdminToken recusa um refresh já expirado", () => {
    assert.equal(validAdminToken(adminToken({ refresh_expires_at: past() })), false);
});

test("validAdminToken recusa tokens em falta ou lixo", () => {
    assert.equal(validAdminToken(adminToken({ access_token: "" })), false);
    assert.equal(validAdminToken(adminToken({ refresh_token: "" })), false);
    assert.equal(validAdminToken(null), false);
    assert.equal(validAdminToken("não é objeto"), false);
});

test("restoreToken devolve um admin válido guardado", () => {
    sessionStorage.setItem(TOKEN_KEY, JSON.stringify(adminToken()));
    assert.equal(restoreToken()?.access_token, "a");
});

test("restoreToken recusa storage adulterado, inválido ou vazio", () => {
    sessionStorage.setItem(TOKEN_KEY, "{ isto não é json");
    assert.equal(restoreToken(), null);

    sessionStorage.setItem(TOKEN_KEY, JSON.stringify(adminToken({ role: "viewer" })));
    assert.equal(restoreToken(), null);

    sessionStorage.removeItem(TOKEN_KEY);
    assert.equal(restoreToken(), null);
});
