import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { reachableFrom } from "./support/module-graph.js";

/**
 * Um nome que nenhum módulo exporta (`SyntaxError`) ou um caminho que não existe
 * (`ERR_MODULE_NOT_FOUND`) derruba a dashboard; as falhas por globais como o `window` ignoram-se.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const ENTRY = path.join(here, "../../src/Dashboard/main.js");
const APP = path.join(here, "../../src/Dashboard/dashboard/app.js");
const MODULE_ROOT = path.join(here, "../../src/Dashboard/dashboard");
const CONFIG_PANEL = path.join(MODULE_ROOT, "devices/config/panel.js");
const CONFIG_HANDLERS = path.join(MODULE_ROOT, "devices/config/handlers.js");

/**
 * As portas de entrada do grafo: cada uma é trazida por `import()`, que avaliar quem chama não
 * segue, e entre elas alcançam o cluster inteiro.
 */
const ENTRY_POINTS = [ENTRY, APP, CONFIG_PANEL, CONFIG_HANDLERS];

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
