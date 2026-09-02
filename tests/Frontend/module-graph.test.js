import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Guarda contra um grafo de módulos ES partido: um nome que um módulo importa e nenhum
 * exporta derruba a dashboard numa página branca, e o `node --check` não o apanha porque cada
 * ficheiro é individualmente válido.
 *
 * Avaliá-los em node falha em globais como o `window` -- isso é esperado e ignorado. Só um
 * `SyntaxError` na ligação é um import genuinamente partido.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const ENTRY = path.join(here, "../../src/Dashboard/main.js");
const MODULE_ROOT = path.join(here, "../../src/Dashboard/dashboard");

/** A porta de entrada do grafo: a dashboard entra toda pelo `main.js`. */
const ENTRY_POINTS = [ENTRY];

test("module graph links: main.js", async () => {
    try {
        await import(ENTRY);
    } catch (error) {
        assert.notEqual(
            error.constructor.name,
            "SyntaxError",
            `broken import in the main.js graph -- ${error.message}`,
        );
    }
});

const listModules = (dir) =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return listModules(full);
        return entry.name.endsWith(".js") ? [full] : [];
    });

const reachableFrom = (entry, seen = new Set()) => {
    if (seen.has(entry) || !fs.existsSync(entry)) return seen;
    seen.add(entry);
    const source = fs.readFileSync(entry, "utf8");
    for (const [, specifier] of source.matchAll(/from\s+["']([^"']+)["']/g)) {
        if (specifier.startsWith(".")) {
            reachableFrom(path.resolve(path.dirname(entry), specifier), seen);
        }
    }
    return seen;
};

test("every dashboard module is reachable from an entry point", () => {
    const reachable = ENTRY_POINTS.reduce((seen, entry) => reachableFrom(entry, seen), new Set());
    const orphans = listModules(MODULE_ROOT).filter((file) => !reachable.has(file));

    // Um órfão é código morto ou um módulo que a verificação de ligação acima nunca vê -- e
    // vale a pena saber das duas coisas no instante em que aparecem.
    assert.deepEqual(
        orphans.map((file) => path.relative(MODULE_ROOT, file)),
        [],
    );
});
