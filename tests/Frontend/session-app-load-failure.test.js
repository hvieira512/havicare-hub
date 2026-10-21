import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

// O `showLogin` avisa por toast, e o toast fala com o SweetAlert, que não existe fora do
// browser. Um duplo mudo chega: o que se afirma aqui é o ecrã, não o aviso.
globalThis.Swal = { fire: () => Promise.resolve({}) };

const { initializeDashboardSession } =
    await import("../../src/Dashboard/dashboard/auth/session.js");

/**
 * O grafo da dashboard entra por `import()` depois de autenticar, e esse pedido pode falhar: o
 * ecrã tem de voltar ao formulário, com a bandeira do arranque baixada.
 */
function mountScreens() {
    document.body.innerHTML = `
        <div id="dashboardLogin"><form id="dashboardLoginForm"></form></div>
        <div id="dashboardApp"></div>`;
    document.body.dataset.dashboardAuthRequired = "false";

    return {
        login: document.getElementById("dashboardLogin"),
        app: document.getElementById("dashboardApp"),
    };
}

const visible = (el) => !el.hidden && !el.classList.contains("d-none");

beforeEach(() => {
    mountScreens();
});

test("if the app fails to load, it goes back to the login screen", async () => {
    const { login, app } = mountScreens();

    await initializeDashboardSession(async () => {
        throw new Error("Failed to fetch dynamically imported module");
    });

    assert.equal(visible(login), true, "o formulário tem de voltar, senão não há onde tentar");
    assert.equal(visible(app), false, "o #app vazio não pode ficar à frente");
});

/** A bandeira do arranque único tem de baixar, ou a segunda tentativa não corre nada. */
test("after a failure, a second attempt starts the app again", async () => {
    mountScreens();
    await initializeDashboardSession(async () => {
        throw new Error("Failed to fetch dynamically imported module");
    });

    const { login, app } = mountScreens();
    let started = 0;
    await initializeDashboardSession(async () => {
        started += 1;
    });

    assert.equal(started, 1, "a segunda tentativa tem de chamar o arranque");
    assert.equal(visible(app), true);
    assert.equal(visible(login), false);
});
