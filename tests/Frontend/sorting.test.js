import test from "node:test";
import assert from "node:assert/strict";

import { nextSort, sortRows } from "../../src/Dashboard/dashboard/sorting.js";

/**
 * A ordenação das listas que carregam inteiras. A das listagens paginadas no servidor não
 * passa por aqui: essa vai no parâmetro `sort` do pedido.
 */

const users = [
    { username: "hugo-vieira", role: "hub_admin", licenses: 4 },
    { username: "havicare", role: "license_client", licenses: 22 },
    { username: "marcus-santos", role: "hub_admin", licenses: 3 },
];

const names = (rows) => rows.map((row) => row.username);

test("carregar num cabeçalho novo ordena por ele, ascendente", () => {
    assert.deepEqual(nextSort(null, "username"), { column: "username", descending: false });
    assert.deepEqual(nextSort({ column: "role", descending: true }, "username"), { column: "username", descending: false });
});

test("carregar outra vez no mesmo inverte, e à terceira desliga", () => {
    // O terceiro estado é a ordem original. Sem ele não há como voltar atrás sem recarregar.
    const first = nextSort(null, "username");
    const second = nextSort(first, "username");

    assert.deepEqual(second, { column: "username", descending: true });
    assert.equal(nextSort(second, "username"), null);
});

test("sem ordenação as linhas ficam na ordem em que vieram", () => {
    assert.deepEqual(names(sortRows(users, null)), ["hugo-vieira", "havicare", "marcus-santos"]);
});

test("ordenar não mexe no array original", () => {
    const original = [...users];
    sortRows(users, { column: "username", descending: false });

    assert.deepEqual(users, original);
});

test("texto ordena-se como português, e não por código de caractere", () => {
    const rows = [{ n: "Zebra" }, { n: "ágil" }, { n: "arara" }];

    assert.deepEqual(
        sortRows(rows, { column: "n", descending: false }).map((r) => r.n),
        ["ágil", "arara", "Zebra"],
    );
});

test("números ordenam-se por valor e não por texto", () => {
    // Como texto, "22" vinha antes de "3". É o defeito clássico de comparar tudo como string.
    assert.deepEqual(
        sortRows(users, { column: "licenses", descending: false }).map((row) => row.licenses),
        [3, 4, 22],
    );
});

test("descendente é o inverso exacto do ascendente", () => {
    const up = names(sortRows(users, { column: "username", descending: false }));
    const down = names(sortRows(users, { column: "username", descending: true }));

    assert.deepEqual(down, [...up].reverse());
});

test("um acessor próprio serve as colunas que não são um campo", () => {
    const label = (row, column) => (column === "role" ? { hub_admin: "Admin", license_client: "Cliente" }[row.role] : row[column]);

    assert.deepEqual(
        sortRows(users, { column: "role", descending: false }, label).map((row) => row.username),
        ["hugo-vieira", "marcus-santos", "havicare"],
    );
});

test("valores em falta ficam no fim, e não a fingir que são os primeiros", () => {
    const rows = [{ n: "b" }, { n: null }, { n: "a" }];

    assert.deepEqual(sortRows(rows, { column: "n", descending: false }).map((r) => r.n), ["a", "b", null]);
    assert.deepEqual(sortRows(rows, { column: "n", descending: true }).map((r) => r.n), ["b", "a", null]);
});
