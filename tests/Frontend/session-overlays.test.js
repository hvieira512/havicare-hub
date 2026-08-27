import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";

const { closeDashboardOverlays } = await import(
    "../../src/Dashboard/dashboard/auth/session.js",
);

/**
 * Cair para o ecrã de entrada com um modal aberto deixava o backdrop por cima do login e o
 * `modal-open` no `body`: um ecrã escuro que não recebia cliques. Limpar isto é do lado de
 * quem mostra o login, porque é ele que sabe que a sessão acabou.
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

test("mostrar o login fecha o modal aberto e não deixa backdrop nenhum", () => {
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

test("o body volta a poder rolar", () => {
    dashboardWithAnOpenModal();

    closeDashboardOverlays();

    assert.equal(document.body.classList.contains("modal-open"), false);
    assert.equal(document.body.style.overflow, "");
    assert.equal(document.body.style.paddingRight, "");
});

test("sem nada aberto não estraga o que está no ecrã", () => {
    document.body.innerHTML = `<div id="dashboardApp"><div class="modal"></div></div>`;
    document.body.classList.remove("modal-open");

    closeDashboardOverlays();

    assert.equal(document.querySelectorAll("#dashboardApp .modal").length, 1);
    assert.equal(document.body.classList.contains("modal-open"), false);
});
