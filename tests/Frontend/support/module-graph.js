import fs from "node:fs";
import path from "node:path";

/**
 * Percorre o grafo de módulos ES lendo os ficheiros, sem os avaliar. O `import()` dinâmico é
 * aresta na mesma; pedir o grafo sem ele mede o que o browser paga antes de o seguir.
 */
const STATIC_IMPORT = /(?:\bfrom|^\s*import)\s+["']([^"']+)["']/gm;
const DYNAMIC_IMPORT = /\bimport\s*\(\s*["']([^"']+)["']/g;

export function specifiersOf(source, { includeDynamic = true } = {}) {
    const specifiers = [...source.matchAll(STATIC_IMPORT)].map(([, specifier]) => specifier);
    if (includeDynamic) {
        specifiers.push(...[...source.matchAll(DYNAMIC_IMPORT)].map(([, specifier]) => specifier));
    }
    return specifiers;
}

/** Os ficheiros alcançáveis a partir das entradas dadas, elas incluídas. */
export function reachableFrom(entries, { includeDynamic = true } = {}) {
    const seen = new Set();
    const visit = (file) => {
        if (seen.has(file) || !fs.existsSync(file)) return;
        seen.add(file);
        for (const specifier of specifiersOf(fs.readFileSync(file, "utf8"), { includeDynamic })) {
            if (specifier.startsWith(".")) {
                visit(path.resolve(path.dirname(file), specifier));
            }
        }
    };

    entries.forEach(visit);
    return seen;
}
