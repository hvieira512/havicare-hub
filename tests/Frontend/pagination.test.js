import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { renderPagination } = await import("../../src/Dashboard/dashboard/pagination.js");

/**
 * O paginador partilhado. Desenha uma página por botão: o resumo saiu da linha e o
 * paginador ficou centrado com a largura toda para si, portanto cabem lá todas.
 */

function render(pagination) {
    const root = document.createElement("div");
    const controls = document.createElement("ul");

    renderPagination({
        pagination,
        rootEl: root,
        summaryEl: null,
        controlsEl: controls,
        actionPrefix: "telemetry",
        goAction: "telemetryPageGo",
    });

    return { root, controls };
}

const numbers = (controls) =>
    [...controls.querySelectorAll("[data-action='telemetryPageGo']")].map((b) => b.dataset.page);

test("desenha uma página por botão, sem saltos", () => {
    const { controls } = render({ total: 167, total_pages: 14, page: 1, limit: 12 });

    assert.deepEqual(numbers(controls), ["1", "2", "3", "4", "5", "6", "7", "8", "9", "10", "11", "12", "13", "14"]);
});

test("não há reticências nenhumas", () => {
    const { controls } = render({ total: 200, total_pages: 17, page: 9, limit: 12 });

    assert.equal(controls.querySelectorAll("span.page-link").length, 0);
    assert.equal(controls.textContent.includes("…"), false);
    assert.equal(numbers(controls).length, 17);
});

test("sem elemento de resumo, desenha na mesma", () => {
    // Os dois painéis do dispositivo já mostram o total numa pastilha ao lado do título, e
    // por isso não têm `<span>` de resumo nenhum para escrever.
    const { root, controls } = render({ total: 167, total_pages: 14, page: 1, limit: 12 });

    assert.equal(root.classList.contains("d-none"), false);
    assert.equal(numbers(controls).length, 14);
});

test("com tudo numa página esconde-se, sem tropeçar no resumo que não existe", () => {
    const { root, controls } = render({ total: 4, total_pages: 1, page: 1, limit: 12 });

    assert.equal(root.classList.contains("d-none"), true);
    assert.equal(controls.innerHTML, "");
});

test("a actual está marcada, e as setas travam nas pontas", () => {
    const { controls } = render({ total: 167, total_pages: 14, page: 9, limit: 12 });

    const actual = controls.querySelector("[aria-current='page']");
    assert.equal(actual.dataset.page, "9");
    assert.equal(actual.closest("li").classList.contains("active"), true);

    assert.equal(controls.querySelector("[data-action='telemetryPrev']").disabled, false);
    assert.equal(controls.querySelector("[data-action='telemetryNext']").disabled, false);

    const first = render({ total: 167, total_pages: 14, page: 1, limit: 12 }).controls;
    assert.equal(first.querySelector("[data-action='telemetryPrev']").disabled, true);

    const last = render({ total: 167, total_pages: 14, page: 14, limit: 12 }).controls;
    assert.equal(last.querySelector("[data-action='telemetryNext']").disabled, true);
});

test("todos os botões de página levam as mesmas classes, para medirem o mesmo", () => {
    const { controls } = render({ total: 167, total_pages: 14, page: 1, limit: 12 });

    const classes = [...controls.querySelectorAll("[data-action='telemetryPageGo']")]
        .map((b) => [...b.classList].sort().join(" "));

    assert.equal(new Set(classes).size, 1, "um botão de página não pode ter classes diferentes de outro");
});
