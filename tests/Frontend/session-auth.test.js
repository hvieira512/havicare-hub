import test from "node:test";
import assert from "node:assert/strict";

// Sem temporizadores reais: os relógios de inatividade são de minutos e deixariam o processo
// de teste pendurado.
window.setTimeout = () => 0;
window.clearTimeout = () => {};

const {
    closeDashboardOverlays,
    initializeDashboardSession,
    initializePasswordVisibility,
    resetPasswordVisibility,
    validAdminToken,
} = await import("../../src/Dashboard/dashboard/auth/session.js");

/**
 * As guardas que decidem se uma sessão vale, e o arranque que a vai buscar ao cookie; no
 * `sessionStorage`, que é por separador, o segundo separador pediria login.
 */
const future = () => new Date(Date.now() + 3_600_000).toISOString();
const past = () => new Date(Date.now() - 1_000).toISOString();

const adminToken = (over = {}) => ({
    role: "hub_admin",
    access_token: "a",
    expires_at: future(),
    ...over,
});

test("validAdminToken accepts an admin with an unexpired access token", () => {
    assert.equal(validAdminToken(adminToken()), true);
});

test("validAdminToken refuses a non-admin role", () => {
    assert.equal(validAdminToken(adminToken({ role: "viewer" })), false);
});

test("validAdminToken refuses an expired access token", () => {
    assert.equal(validAdminToken(adminToken({ expires_at: past() })), false);
});

test("validAdminToken refuses missing or garbage tokens", () => {
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

const sessionResponse = (status, body) => Response.json(body, { status });

test("without a session cookie, startup shows the login", async () => {
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

test("a new tab signs in through the cookie, with no stored credential", async () => {
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

/**
 * Cair para o ecrã de entrada limpa o backdrop e o `modal-open` de um modal aberto: é quem
 * mostra o login que sabe que a sessão acabou.
 */
function dashboardWithAnOpenModal() {
    document.body.innerHTML = `
        <div id="dashboardApp">
            <div id="deviceModal" class="modal show" role="dialog" aria-modal="true"></div>
        </div>
        <div class="modal-backdrop show"></div>
        <div class="offcanvas-backdrop show"></div>`;
    document.body.classList.add("modal-open");
    document.body.style.overflow = "hidden";
    document.body.style.paddingRight = "17px";
}

test("showing the login closes the open modal and leaves no backdrop", () => {
    dashboardWithAnOpenModal();

    closeDashboardOverlays();

    const modal = document.getElementById("deviceModal");
    assert.equal(modal.classList.contains("show"), false);
    assert.equal(modal.style.display, "none");
    assert.equal(modal.getAttribute("aria-hidden"), "true");
    // O modal escondido sai da árvore de acessibilidade: sem `role` nem `aria-modal`, um
    // leitor de ecrã não anuncia um diálogo que já não está lá.
    assert.equal(modal.hasAttribute("aria-modal"), false);
    assert.equal(modal.hasAttribute("role"), false);
    assert.equal(document.querySelectorAll(".modal-backdrop, .offcanvas-backdrop").length, 0);
});

test("the body can scroll again", () => {
    dashboardWithAnOpenModal();

    closeDashboardOverlays();

    assert.equal(document.body.classList.contains("modal-open"), false);
    assert.equal(document.body.style.overflow, "");
    assert.equal(document.body.style.paddingRight, "");
});

test("with nothing open it does not disturb the screen", () => {
    document.body.innerHTML = `<div id="dashboardApp"><div class="modal"></div></div>`;
    document.body.classList.remove("modal-open");

    closeDashboardOverlays();

    assert.equal(document.querySelectorAll("#dashboardApp .modal").length, 1);
    assert.equal(document.body.classList.contains("modal-open"), false);
});

/**
 * O olho que mostra a palavra-passe. O que importa prender é fechar-se ao sair: o ecrã
 * seguinte é de outra pessoa.
 */
const mount = () => {
    document.body.innerHTML = `
        <input id="dashboardLoginPassword" type="password">
        <button type="button" data-password-toggle="dashboardLoginPassword"
                aria-pressed="false" aria-label="Mostrar a palavra-passe">
            <i class="fa-solid fa-eye"></i>
        </button>
    `;

    return {
        input: document.getElementById("dashboardLoginPassword"),
        button: document.querySelector("[data-password-toggle]"),
    };
};

test("pressing the eye shows the password and pressing again hides it", () => {
    const { input, button } = mount();
    initializePasswordVisibility();

    button.click();
    assert.equal(input.getAttribute("type"), "text");

    button.click();
    assert.equal(input.getAttribute("type"), "password");
});

test("returning to the login screen hides the password again", () => {
    const { input, button } = mount();
    initializePasswordVisibility();

    button.click();
    assert.equal(input.getAttribute("type"), "text");

    resetPasswordVisibility();

    assert.equal(input.getAttribute("type"), "password");
    assert.equal(button.getAttribute("aria-pressed"), "false");
    assert.ok(button.querySelector("i").classList.contains("fa-eye"));
});

test("without an eye on the page, binding the behaviour does not break", () => {
    document.body.innerHTML = "";

    assert.doesNotThrow(() => initializePasswordVisibility());
    assert.doesNotThrow(() => resetPasswordVisibility());
});

test("a non-admin login revokes the session cookie it was just given", async () => {
    installDom();
    globalThis.Swal ??= { fire: async () => ({}) };
    const token = { role: "viewer", access_token: "viewer-token", expires_at: future() };
    const calls = captureFetch((url) => (url === "/api/auth/logout"
        ? sessionResponse(200, { status: "ok" })
        : sessionResponse(200, { status: "ok", token })));
    await initializeDashboardSession(async () => {});
    calls.length = 0;

    document.getElementById("dashboardLoginUsername").value = "viewer";
    document.getElementById("dashboardLoginPassword").value = "secret";
    document.getElementById("dashboardLoginForm").dispatchEvent(new window.Event("submit", { cancelable: true }));
    await new Promise((resolve) => setImmediate(resolve));

    const logout = calls.find((call) => call.url === "/api/auth/logout");
    assert.ok(logout, "the refresh cookie stays alive in the browser");
    assert.equal(logout.options.headers.Authorization, "Bearer viewer-token");
});
