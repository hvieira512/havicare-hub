import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { ensureAgGrid } from "../../src/Dashboard/dashboard/grid.js";

const SRC = "/assets/vendor/ag-grid/ag-grid-community.min.js";

/**
 * O AG Grid são 2 MB que só as definições de utilizadores precisam. Deixou de vir no
 * `index.php` e carrega-se à primeira grelha -- uma vez só, mesmo com duas chamadas.
 */
test("o AG Grid injeta-se uma vez só, sob demanda", () => {
    assert.equal(
        document.querySelectorAll(`script[src="${SRC}"]`).length,
        0,
        "não vem no arranque",
    );

    ensureAgGrid();
    ensureAgGrid();

    assert.equal(
        document.querySelectorAll(`script[src="${SRC}"]`).length,
        1,
        "duas chamadas partilham a mesma carga",
    );
});
