import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { reachableFrom } from "./support/module-graph.js";

/**
 * Guarda contra um grafo de módulos ES partido: um nome que um módulo importa e nenhum
 * exporta derruba a dashboard numa página branca, e o `node --check` não o apanha porque cada
 * ficheiro é individualmente válido.
 *
 * Avaliá-los em node falha em globais como o `window` -- isso é esperado e ignorado. O que
 * não se ignora são as duas formas de um import partido: um nome que ninguém exporta, que dá
 * `SyntaxError`, e um caminho para um ficheiro que não existe, que dá `ERR_MODULE_NOT_FOUND`.
 * A segunda é a que aparece ao mover ou apagar um módulo, que é precisamente quando isto é
 * preciso.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const ENTRY = path.join(here, "../../src/Dashboard/main.js");
const APP = path.join(here, "../../src/Dashboard/dashboard/app.js");
const MODULE_ROOT = path.join(here, "../../src/Dashboard/dashboard");

/**
 * As portas de entrada do grafo. O `app.js` é nomeado à parte porque o `main.js` o traz por
 * `import()`: avaliar o `main.js` não liga nada do que está por trás dele, e sem o nomear
 * aqui um import partido lá dentro passava sem ninguém dar por ele.
 */
const ENTRY_POINTS = [ENTRY, APP];

for (const entry of ENTRY_POINTS) {
    test(`as ligações do grafo de módulos a partir do ${path.basename(entry)}`, async () => {
        try {
            await import(entry);
        } catch (error) {
            const broken = error.constructor.name === "SyntaxError" ||
                error.code === "ERR_MODULE_NOT_FOUND";
            assert.ok(
                !broken,
                `import partido no grafo do ${path.basename(entry)} -- ${error.message}`,
            );
        }
    });
}

const listModules = (dir) =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return listModules(full);
        return entry.name.endsWith(".js") ? [full] : [];
    });

test("every dashboard module is reachable from an entry point", () => {
    const reachable = reachableFrom(ENTRY_POINTS);
    const orphans = listModules(MODULE_ROOT).filter((file) => !reachable.has(file));

    // Um órfão é código morto ou um módulo que a verificação de ligação acima nunca vê -- e
    // vale a pena saber das duas coisas no instante em que aparecem.
    assert.deepEqual(
        orphans.map((file) => path.relative(MODULE_ROOT, file)),
        [],
    );
});
