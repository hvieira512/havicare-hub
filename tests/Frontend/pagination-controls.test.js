import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { paginationControls } from "../../src/Dashboard/dashboard/components/pagination.js";

/**
 * A janela do paginador, sem um documento à volta.
 *
 * São sempre sete lugares: um paginador com sete botões numa página e nove noutra muda de
 * tamanho debaixo do rato de quem carregou nele. A regra é do componente e testava-se só
 * através de um `jsdom` e de três elementos a fingir de painel.
 */
const janela = (page, totalPages) =>
    [...parseFragment(paginationControls({
        pagination: { page, total_pages: totalPages },
        actionPrefix: "devices",
    })).querySelectorAll("li")]
        .slice(1, -1)
        .map((li) => li.textContent.trim());

test("com poucas páginas mostra-as todas, sem reticências", () => {
    assert.deepEqual(janela(2, 5), ["1", "2", "3", "4", "5"]);
});

test("a janela tem sempre sete lugares quando há páginas que cheguem", () => {
    for (const page of [1, 4, 10, 47, 50]) {
        assert.equal(janela(page, 50).length, 7, `página ${page}`);
    }
});

test("no meio, a página actual fica ao centro entre duas reticências", () => {
    assert.deepEqual(janela(10, 50), ["1", "…", "9", "10", "11", "…", "50"]);
});

test("junto às pontas a vizinhança encosta-se, e não sobra lugar por preencher", () => {
    assert.deepEqual(janela(2, 50), ["1", "2", "3", "4", "5", "…", "50"]);
    assert.deepEqual(janela(49, 50), ["1", "…", "46", "47", "48", "49", "50"]);
});

test("a página actual anuncia-se a quem não vê o destaque", () => {
    const controls = parseFragment(paginationControls({
        pagination: { page: 3, total_pages: 9 },
        actionPrefix: "devices",
    }));

    assert.equal(controls.querySelector("[aria-current=\"page\"]").textContent.trim(), "3");
});

test("as setas travam nas pontas", () => {
    const primeira = parseFragment(paginationControls({
        pagination: { page: 1, total_pages: 9 },
        actionPrefix: "devices",
    }));
    const ultima = parseFragment(paginationControls({
        pagination: { page: 9, total_pages: 9 },
        actionPrefix: "devices",
    }));

    assert.equal(primeira.querySelector("[data-action=\"devicesPrev\"]").disabled, true);
    assert.equal(primeira.querySelector("[data-action=\"devicesNext\"]").disabled, false);
    assert.equal(ultima.querySelector("[data-action=\"devicesNext\"]").disabled, true);
});

test("uma página só não tem controlos nenhuns", () => {
    assert.equal(paginationControls({ pagination: { page: 1, total_pages: 1 }, actionPrefix: "devices" }), "");
});

/** Os painéis do dispositivo registam o handler noutro nome, e os números têm de o usar. */
test("o nome da acção dos números pode não seguir o prefixo", () => {
    const controls = parseFragment(paginationControls({
        pagination: { page: 2, total_pages: 9 },
        actionPrefix: "telemetryPage",
        goAction: "telemetryPageGo",
    }));

    assert.ok(controls.querySelector("[data-action=\"telemetryPageGo\"][data-page=\"1\"]"));
});
