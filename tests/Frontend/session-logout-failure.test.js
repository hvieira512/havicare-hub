import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { flush } from "./support/deferred-fetch.js";

const fired = [];
globalThis.Swal = {
    fire: (options) => {
        fired.push(options);
        return Promise.resolve({});
    },
};

const { initializeDashboardSession } =
    await import("../../src/Dashboard/dashboard/auth/session.js");

/**
 * O pedido de saída podia falhar em silêncio: o ecrã caía para a entrada, mas o cookie e os
 * dois tokens ficavam vivos no Hub, e recarregar voltava a entrar sem palavra-passe.
 */
const mount = () => {
    fired.length = 0;
    document.body.innerHTML = `
        <div id="dashboardLogin" class="d-none" hidden>
            <form id="dashboardLoginForm"><input id="dashboardLoginUsername"></form>
        </div>
        <div id="dashboardApp"></div>
        <button id="dashboardLogoutBtn"></button>`;
    document.body.dataset.dashboardAuthRequired = "false";

    return {
        login: document.getElementById("dashboardLogin"),
        logoutButton: document.getElementById("dashboardLogoutBtn"),
    };
};

const visible = (el) => !el.hidden && !el.classList.contains("d-none");
const warnings = () => fired.filter((options) => options.icon === "warning");

/**
 * Esperar pela resposta antes de esconder a dashboard deixava os dados de doentes no ecrã
 * enquanto o pedido não caísse -- e o `fetch` não tem prazo.
 */
test("sai-se do ecrã antes de esperar pelo Hub, e não depois", async () => {
    const { login, logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    // Um pedido que nunca responde é a rede caída: o ecrã não pode ficar à espera dele.
    globalThis.fetch = () => new Promise(() => {});

    logoutButton.click();
    await flush();

    assert.equal(visible(login), true);
});

test("uma saída que o Hub não chegou a receber diz-se a quem saiu", async () => {
    const { login, logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => {
        throw new TypeError("Failed to fetch");
    };

    logoutButton.click();
    await flush();

    assert.equal(visible(login), true, "sai-se na mesma: ninguém fica preso na dashboard");
    assert.match(
        warnings()[0]?.titleText ?? "",
        /sess(ã|a)o/i,
        "o aviso tem de existir, senão a sessão fica aberta sem ninguém saber",
    );
});

test("um 500 do Hub conta como não ter saído", async () => {
    const { logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => ({ ok: false, status: 500 });

    logoutButton.click();
    await flush();

    assert.equal(warnings().length, 1);
});

/** O porquê da saída e o aviso da sessão são dois factos, e não se substituem um ao outro. */
test("o motivo da saída não se perde quando o pedido falha", async () => {
    mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => ({ ok: false, status: 500 });

    // Uma saída com motivo -- a que o relógio de inatividade dispara é assim.
    window.dispatchEvent(new Event("hub-dashboard-auth-required"));
    await flush();

    const ditos = warnings().map((options) => options.titleText).join(" | ");
    assert.match(ditos, /expirou|inatividade/i, "o motivo tem de continuar a ser dito");
    assert.match(ditos, /pode continuar aberta/i, "e o aviso da sessão também");
});

test("quando o Hub recebe a saída, não se avisa nada", async () => {
    const { login, logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => ({ ok: true, status: 200 });

    logoutButton.click();
    await flush();

    assert.equal(visible(login), true);
    assert.deepEqual(warnings(), []);
});
