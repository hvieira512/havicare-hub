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

test("quando o Hub recebe a saída, não se avisa nada", async () => {
    const { login, logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => ({ ok: true, status: 200 });

    logoutButton.click();
    await flush();

    assert.equal(visible(login), true);
    assert.deepEqual(warnings(), []);
});
