import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Guarda contra um grafo de módulos ES partido.
 *
 * A ligação resolve todos os imports do grafo antes de qualquer código correr, e por isso um
 * nome que um módulo importa e nenhum exporta derruba a dashboard inteira numa página branca
 * -- nenhum script executa. O `node --check` não o apanha, porque cada ficheiro é
 * individualmente válido.
 *
 * O ponto de entrada é o `main.js`, que é o que o `index.php` carrega. Verificar outro
 * qualquer deixava os módulos mais próximos da entrada sem guarda, que é onde uma página
 * branca dói mais.
 *
 * Os módulos são código de browser, e por isso avaliá-los em node falha em globais como o
 * `window`. Isso é esperado e ignorado: só um `SyntaxError` na ligação é que quer dizer um
 * import genuinamente partido.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const ENTRY = path.join(here, "../../src/Dashboard/main.js");
const MODULE_ROOT = path.join(here, "../../src/Dashboard/dashboard");

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

test("every dashboard module is reachable from the entry point", () => {
    const reachable = reachableFrom(ENTRY);
    const orphans = listModules(MODULE_ROOT).filter((file) => !reachable.has(file));

    // Um órfão é código morto ou um módulo que a verificação de ligação acima nunca vê -- e
    // vale a pena saber das duas coisas no instante em que aparecem.
    assert.deepEqual(
        orphans.map((file) => path.relative(MODULE_ROOT, file)),
        [],
    );
});
