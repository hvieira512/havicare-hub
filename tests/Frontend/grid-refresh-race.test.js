import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

/**
 * A grelha dos Utilizadores API pede as linhas ao servidor a cada página, ordenação ou
 * filtro, e nada cancela o pedido anterior. Dois cliques seguidos no «Seguinte» põem dois
 * pedidos no ar.
 *
 * Com as respostas trocadas ficavam as linhas da página errada **e** o número de página
 * interno errado -- e aí nem o clique seguinte endireitava, porque passava a contar a partir
 * do número errado. As outras vistas assíncronas da dashboard já têm esta guarda.
 */
let rowData;
let gridApi;

const stubAgGrid = () => {
    globalThis.agGrid = {
        ModuleRegistry: { registerModules: () => {} },
        AllCommunityModule: {},
        themeQuartz: { withParams: () => ({}) },
        createGrid: () => {
            gridApi = {
                getColumnState: () => [],
                getFilterModel: () => ({}),
                getColumns: () => [],
                setGridOption: (name, value) => {
                    if (name === "rowData") rowData = value;
                },
            };
            return gridApi;
        },
    };
};

/** Respostas que só chegam quando o teste mandar, que é como uma corrida se reproduz. */
const deferredLoader = () => {
    const pending = [];
    const load = (params) => new Promise((resolve) => {
        pending.push({ page: params.page, resolve });
    });

    return {
        load,
        respond(page) {
            const index = pending.findIndex((entry) => entry.page === page);
            if (index === -1) throw new Error(`Nenhum pedido pendente para a página ${page}`);
            const [entry] = pending.splice(index, 1);
            entry.resolve({
                data: [{ id: page, name: `linha da página ${page}` }],
                pagination: { page, total_pages: 5, total: 5, limit: 1 },
                columns: [],
            });
            return new Promise((done) => setTimeout(done, 0));
        },
    };
};

beforeEach(() => {
    rowData = null;
    gridApi = null;
    stubAgGrid();
});

const buildGrid = async (load, onPage = () => {}) => {
    const { createGrid } = await import("../../src/Dashboard/dashboard/grid.js");
    const element = document.createElement("div");
    document.body.appendChild(element);

    return createGrid({
        element,
        columns: [{ field: "name" }],
        load,
        save: async () => {},
        onError: () => {},
        onPage,
        pageSize: 1,
    });
};

test("a resposta que chega atrasada não substitui a da página pedida a seguir", async () => {
    const { load, respond } = deferredLoader();
    const grid = await buildGrid(load);

    const second = grid.goToPage(2);
    const third = grid.goToPage(3);

    await respond(3);
    await respond(2);
    await Promise.all([second, third]);

    assert.deepEqual(rowData, [{ id: 3, name: "linha da página 3" }], "fica a última pedida");
});

/**
 * O paginador do ecrã tira a página actual do `onPage`, e é dela que calcula o «Seguinte».
 * Deixar passar a paginação da resposta atrasada punha o paginador a anunciar uma página e a
 * grelha a mostrar outra, e o clique a seguir partia do número errado.
 */
test("a paginação anunciada é a da última pedida, e não a da última a chegar", async () => {
    const { load, respond } = deferredLoader();
    const announced = [];
    const grid = await buildGrid(load, (pagination) => announced.push(pagination.page));

    const second = grid.goToPage(2);
    const third = grid.goToPage(3);
    await respond(3);
    await respond(2);
    await Promise.all([second, third]);

    assert.deepEqual(announced, [3], "a resposta ultrapassada não anuncia nada");
});

test("sem corrida, a resposta escreve as linhas como sempre", async () => {
    const { load, respond } = deferredLoader();
    const grid = await buildGrid(load);

    const first = grid.goToPage(1);
    await respond(1);
    await first;

    assert.deepEqual(rowData, [{ id: 1, name: "linha da página 1" }]);
});
