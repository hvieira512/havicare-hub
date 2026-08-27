import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";

/**
 * O `confirm()` do browser devolvia um booleano de imediato; o `Swal.fire()` devolve uma
 * promessa. Trocar um pelo outro sem esperar transforma um apagar guardado num apagar
 * directo, e é isso que estes testes trancam: cancelar não pode chegar à API.
 */
const calls = [];
let answer = { isConfirmed: false };

globalThis.Swal = { fire: () => Promise.resolve(answer) };
globalThis.fetch = (url, options) => {
    calls.push({ url, method: options?.method || "GET" });
    return Promise.resolve({
        ok: false,
        status: 500,
        text: () => Promise.resolve("{\"error\":{\"code\":\"boom\"}}"),
    });
};

const { deleteApiUser } = await import(
    "../../src/Dashboard/dashboard/settings/api-users.js",
);

test("cancelar a confirmação não chega a chamar a API", async () => {
    calls.length = 0;
    answer = { isConfirmed: false, dismiss: "cancel" };

    await deleteApiUser(7);

    assert.deepEqual(calls, []);
});

test("confirmar apaga, e apaga o que se pediu", async () => {
    calls.length = 0;
    answer = { isConfirmed: true };

    await deleteApiUser(7);

    assert.deepEqual(calls, [{ url: "/api/users/7", method: "DELETE" }]);
});
