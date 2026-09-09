import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { GRID_LOCALE, toColumnDef } from "../../src/Dashboard/dashboard/grid.js";

/**
 * A grelha dos utilizadores API falava inglês em dois sítios, e ambos só se ouvem: o campo
 * de filtro anunciava-se como «Utilizador Filter Input», e o botão do menu de filtro como
 * «Open Filter Menu». Não se vêem no ecrã, e por isso ninguém os tinha corrigido.
 *
 * O `Filtrar ` do nosso próprio `<select>` era pior: o nome da coluna não chegava ao
 * componente e a etiqueta ficava com a palavra sozinha, sem dizer o que se filtra.
 */
test("as etiquetas que a biblioteca lê estão em português", () => {
    for (const value of Object.values(GRID_LOCALE)) {
        assert.doesNotMatch(value, /Filter|Menu|Page|Rows/);
    }
    assert.ok(GRID_LOCALE.ariaFilterInput);
    assert.ok(GRID_LOCALE.ariaFilterMenuOpen);
});

test("o filtro de opções recebe o nome da coluna", () => {
    const definition = toColumnDef(
        { field: "role", filter: { type: "select", param: "role", options: [] } },
        { titles: { role: "Perfil" }, labels: {}, renderers: {}, register: () => {} },
    );

    assert.equal(definition.floatingFilterComponentParams.headerName, "Perfil");
});
