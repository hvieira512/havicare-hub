import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

// O `showLogin` avisa por toast, e o toast fala com o SweetAlert, que não existe fora do
// browser. Um duplo mudo chega: o que se afirma aqui é o ecrã, não o aviso.
globalThis.Swal = { fire: () => Promise.resolve({}) };

const { initializeDashboardSession } =
    await import("../../src/Dashboard/dashboard/auth/session.js");

/**
 * O grafo da dashboard entra por `import()` depois de autenticar, e esse pedido pode falhar
 * -- a rede a oscilar, ou um deploy a trocar o ficheiro com o login em curso.
 *
 * Quando falhava, o ecrã ficava num estado sem saída: o formulário já estava escondido, o
 * `#app` visível e vazio, e a bandeira que impede um arranque duplo ficava levantada. O
 * aviso dizia «volte a tentar» e não havia onde carregar. Só um F5 saía dali.
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

test("se a aplicação falhar a carregar, volta-se ao ecrã de entrada", async () => {
    const { login, app } = mountScreens();

    await initializeDashboardSession(async () => {
        throw new Error("Failed to fetch dynamically imported module");
    });

    assert.equal(visible(login), true, "o formulário tem de voltar, senão não há onde tentar");
    assert.equal(visible(app), false, "o #app vazio não pode ficar à frente");
});

/** A bandeira do arranque único tem de baixar, ou a segunda tentativa não corre nada. */
test("depois de falhar, uma segunda tentativa volta a arrancar a aplicação", async () => {
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
