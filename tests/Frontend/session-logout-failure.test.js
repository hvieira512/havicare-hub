import test from "node:test";
import assert from "node:assert/strict";

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
 * O pedido de saída não pode falhar em silêncio: o ecrã cai para a entrada, e se o cookie e
 * os dois tokens ficarem vivos no Hub, recarregar entra sem palavra-passe.
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

/** Esperar pela resposta deixaria os dados de doentes no ecrã, e o `fetch` não tem prazo. */
test("the screen is left before waiting for the Hub, not after", async () => {
    const { login, logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    // Um pedido que nunca responde é a rede caída: o ecrã não pode ficar à espera dele.
    globalThis.fetch = () => new Promise(() => {});

    logoutButton.click();
    await flush();

    assert.equal(visible(login), true);
});

test("a logout the Hub never received is told to the user", async () => {
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

test("a 500 from the Hub counts as not logged out", async () => {
    const { logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => new Response(null, { status: 500 });

    logoutButton.click();
    await flush();

    assert.equal(warnings().length, 1);
});

/** O porquê da saída e o aviso da sessão são dois factos, e não se substituem um ao outro. */
test("the logout reason is not lost when the request fails", async () => {
    mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => new Response(null, { status: 500 });

    // Uma saída com motivo -- a que o relógio de inatividade dispara é assim.
    window.dispatchEvent(new Event("hub-dashboard-auth-required"));
    await flush();

    const shown = warnings().map((options) => options.titleText).join(" | ");
    assert.match(shown, /expirou|inatividade/i, "o motivo tem de continuar a ser dito");
    assert.match(shown, /pode continuar aberta/i, "e o aviso da sessão também");
});

test("when the Hub receives the logout, nothing is announced", async () => {
    const { login, logoutButton } = mount();
    await initializeDashboardSession(async () => {});
    globalThis.fetch = async () => new Response();

    logoutButton.click();
    await flush();

    assert.equal(visible(login), true);
    assert.deepEqual(warnings(), []);
});
