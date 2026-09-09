import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { renderPagination } = await import("../../src/Dashboard/dashboard/pagination.js");

/**
 * O paginador partilhado. Desenha uma janela de páginas com um número fixo de lugares: uma
 * página por botão dava catorze botões em duzentos eventos, que quebravam para duas filas e
 * mudavam a altura da lista, e cresciam sem limite com o histórico.
 *
 * O que estes testes prendem é a largura constante. Um paginador com sete lugares na página 1
 * e nove na página 7 muda de tamanho debaixo do rato de quem carregou nele.
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

const labels = (controls) =>
    [...controls.querySelectorAll(".page-link")].slice(1, -1).map((el) => el.textContent);

test("a janela tem os mesmos lugares em qualquer página", () => {
    const slots = [1, 4, 5, 7, 10, 11, 14].map(
        (page) =>
            render({ total: 167, total_pages: 14, page, limit: 12 }).controls.querySelectorAll("li")
                .length,
    );

    // Sete lugares mais as duas setas, e o mesmo número em todas as páginas.
    assert.deepEqual(slots, [9, 9, 9, 9, 9, 9, 9]);
});

test("as reticências marcam o que ficou de fora, e as pontas estão sempre lá", () => {
    const page = (n) => labels(render({ total: 200, total_pages: 17, page: n, limit: 12 }).controls);

    assert.deepEqual(page(1), ["1", "2", "3", "4", "5", "…", "17"]);
    assert.deepEqual(page(9), ["1", "…", "8", "9", "10", "…", "17"]);
    assert.deepEqual(page(17), ["1", "…", "13", "14", "15", "16", "17"]);
});

test("com poucas páginas mostram-se todas, sem reticências", () => {
    const { controls } = render({ total: 60, total_pages: 5, page: 3, limit: 12 });

    assert.deepEqual(numbers(controls), ["1", "2", "3", "4", "5"]);
    assert.equal(controls.textContent.includes("…"), false);
});

test("as reticências não respondem ao clique", () => {
    const { controls } = render({ total: 200, total_pages: 17, page: 9, limit: 12 });
    const gaps = [...controls.querySelectorAll("li")].filter((li) => li.textContent === "…");

    assert.equal(gaps.length, 2);
    for (const gap of gaps) {
        assert.equal(gap.classList.contains("disabled"), true);
        assert.equal(gap.querySelector("[data-action]"), null);
    }
});

test("sem elemento de resumo, desenha na mesma", () => {
    // Os dois painéis do dispositivo já mostram o total numa pastilha ao lado do título, e
    // por isso não têm `<span>` de resumo nenhum para escrever.
    const { root, controls } = render({ total: 167, total_pages: 14, page: 1, limit: 12 });

    assert.equal(root.classList.contains("d-none"), false);
    assert.deepEqual(numbers(controls), ["1", "2", "3", "4", "5", "14"]);
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
