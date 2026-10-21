import test from "node:test";
import assert from "node:assert/strict";

const { renderPagination } = await import("../../src/Dashboard/dashboard/pagination.js");

/**
 * O paginador partilhado desenha uma janela com um número fixo de lugares, para a largura não
 * mudar de página para página.
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
    });

    return { root, controls };
}

const numbers = (controls) =>
    [...controls.querySelectorAll("[data-action='telemetryGo']")].map((b) => b.dataset.page);

const labels = (controls) =>
    [...controls.querySelectorAll(".page-link")].slice(1, -1).map((el) => el.textContent);

test("the window has the same number of slots on every page", () => {
    const slots = [1, 4, 5, 7, 10, 11, 14].map(
        (page) =>
            render({ total: 167, totalPages: 14, page, limit: 12 }).controls.querySelectorAll("li")
                .length,
    );

    // Sete lugares mais as duas setas, e o mesmo número em todas as páginas.
    assert.deepEqual(slots, [9, 9, 9, 9, 9, 9, 9]);
});

test("ellipses mark what was left out, and the ends are always there", () => {
    const page = (n) => labels(render({ total: 200, totalPages: 17, page: n, limit: 12 }).controls);

    assert.deepEqual(page(1), ["1", "2", "3", "4", "5", "…", "17"]);
    assert.deepEqual(page(9), ["1", "…", "8", "9", "10", "…", "17"]);
    assert.deepEqual(page(17), ["1", "…", "13", "14", "15", "16", "17"]);
});

test("with few pages all are shown, without ellipses", () => {
    const { controls } = render({ total: 60, totalPages: 5, page: 3, limit: 12 });

    assert.deepEqual(numbers(controls), ["1", "2", "3", "4", "5"]);
    assert.equal(controls.textContent.includes("…"), false);
});

test("ellipses do not respond to clicks", () => {
    const { controls } = render({ total: 200, totalPages: 17, page: 9, limit: 12 });
    const gaps = [...controls.querySelectorAll("li")].filter((li) => li.textContent === "…");

    assert.equal(gaps.length, 2);
    for (const gap of gaps) {
        assert.equal(gap.classList.contains("disabled"), true);
        assert.equal(gap.querySelector("[data-action]"), null);
    }
});

test("without a summary element it still renders", () => {
    // Os dois painéis do dispositivo já mostram o total numa pastilha ao lado do título, e
    // por isso não têm `<span>` de resumo nenhum para escrever.
    const { root, controls } = render({ total: 167, totalPages: 14, page: 1, limit: 12 });

    assert.equal(root.classList.contains("d-none"), false);
    assert.deepEqual(numbers(controls), ["1", "2", "3", "4", "5", "14"]);
});

test("with everything on one page it hides, without tripping on a missing summary", () => {
    const { root, controls } = render({ total: 4, totalPages: 1, page: 1, limit: 12 });

    assert.equal(root.classList.contains("d-none"), true);
    assert.equal(controls.innerHTML, "");
});

test("the current page is marked, and the arrows stop at the ends", () => {
    const { controls } = render({ total: 167, totalPages: 14, page: 9, limit: 12 });

    const actual = controls.querySelector("[aria-current='page']");
    assert.equal(actual.dataset.page, "9");
    assert.equal(actual.closest("li").classList.contains("active"), true);

    assert.equal(controls.querySelector("[data-action='telemetryPrev']").disabled, false);
    assert.equal(controls.querySelector("[data-action='telemetryNext']").disabled, false);

    const first = render({ total: 167, totalPages: 14, page: 1, limit: 12 }).controls;
    assert.equal(first.querySelector("[data-action='telemetryPrev']").disabled, true);

    const last = render({ total: 167, totalPages: 14, page: 14, limit: 12 }).controls;
    assert.equal(last.querySelector("[data-action='telemetryNext']").disabled, true);
});
