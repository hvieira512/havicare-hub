import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { requestParams } = await import("../../src/Dashboard/dashboard/grid.js");

/**
 * A tradução do estado do cabeçalho para o pedido: é a costura entre a grelha e a API, e a
 * peça que se parte em silêncio -- um filtro deixa de estreitar, uma ordenação deixa de
 * ordenar, e a tabela continua a desenhar-se como se nada fosse.
 *
 * O `param` de cada filtro vem do descritor e não de um mapa escrito no cliente: é assim que
 * uma coluna nova passa a filtrar sem se tocar no `grid.js`.
 */
const COLUMNS = [
    { field: "imei", sortable: true, editable: true, filter: { type: "text", param: "imei" } },
    {
        field: "deviceType",
        sortable: true,
        editable: true,
        filter: { type: "select", param: "deviceType", multiple: true, options: [] },
    },
    { field: "licenseName", sortable: true, editable: false, filter: null },
    { field: "notas", sortable: false, editable: false, filter: null },
];

test("sem ordenação nem filtros vai só a página e o tamanho", () => {
    assert.deepEqual(requestParams(COLUMNS, 2, 15, [], {}), { page: 2, limit: 15 });
});

test("uma coluna ordenável sai como par de coluna e sentido", () => {
    const params = requestParams(COLUMNS, 1, 20, [{ colId: "imei", sort: "asc", sortIndex: 0 }], {});

    assert.equal(params.sort, "imei:asc");
});

/** O `sortIndex` é a precedência, e não a ordem por que o AG Grid devolve o estado. */
test("várias colunas saem pela precedência e não pela ordem do estado", () => {
    const params = requestParams(COLUMNS, 1, 20, [
        { colId: "deviceType", sort: "desc", sortIndex: 1 },
        { colId: "imei", sort: "asc", sortIndex: 0 },
    ], {});

    assert.equal(params.sort, "imei:asc,deviceType:desc");
});

/** Uma coluna que o descritor não diz ordenável não pode aparecer no pedido. */
test("uma coluna não ordenável é ignorada mesmo com estado de ordenação", () => {
    const params = requestParams(COLUMNS, 1, 20, [{ colId: "notas", sort: "asc", sortIndex: 0 }], {});

    assert.equal(params.sort, undefined);
});

test("o filtro de texto do AG Grid guarda o valor em `filter`", () => {
    const params = requestParams(COLUMNS, 1, 20, [], {
        imei: { filterType: "text", type: "contains", filter: "8612" },
    });

    assert.equal(params.imei, "8612");
});

test("o filtro de escolha guarda-o em `value`", () => {
    const params = requestParams(COLUMNS, 1, 20, [], { deviceType: { value: "watch" } });

    assert.equal(params.deviceType, "watch");
});

test("os dois filtros viajam juntos, cada um no seu parâmetro", () => {
    const params = requestParams(COLUMNS, 1, 20, [], {
        imei: { filter: "8612" },
        deviceType: { value: "watch" },
    });

    assert.deepEqual(params, { page: 1, limit: 20, imei: "8612", deviceType: "watch" });
});

/** Limpar um filtro deixa o modelo com valor vazio, e isso não é um filtro. */
test("um filtro vazio não entra no pedido", () => {
    const params = requestParams(COLUMNS, 1, 20, [], { imei: { filter: "" }, deviceType: { value: "" } });

    assert.deepEqual(params, { page: 1, limit: 20 });
});

/** O parâmetro é o que o descritor declarou, e pode não ser o nome do campo. */
test("o parâmetro sai do descritor e não do nome da coluna", () => {
    const columns = [{
        field: "company_name",
        sortable: true,
        filter: { type: "select", param: "company", multiple: true, options: [] },
    }];
    const params = requestParams(columns, 1, 20, [], { company_name: { value: "hitcare" } });

    assert.equal(params.company, "hitcare");
    assert.equal(params.company_name, undefined);
});

/** Uma coluna sem filtro no descritor não pode gerar parâmetro nenhum. */
test("uma coluna sem filtro é ignorada mesmo com modelo de filtro", () => {
    const params = requestParams(COLUMNS, 1, 20, [], { licenseName: { filter: "gucc" } });

    assert.deepEqual(params, { page: 1, limit: 20 });
});
