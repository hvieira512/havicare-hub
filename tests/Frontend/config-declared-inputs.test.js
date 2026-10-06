import assert from "node:assert/strict";
import test from "node:test";
import { readdirSync, readFileSync } from "node:fs";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

/**
 * Um tipo de campo que o registo não conhece não rebenta: degrada para o editor de JSON em cru,
 * e só se veria no ecrã.
 */

/**
 * O `json` é o recuo declarado de propósito por duas entradas da Wonlex que o painel nem
 * chega a mostrar -- não têm capacidade associada. É o único nome sem renderizador próprio.
 */
const FALLBACK = "json";

const DEFINITIONS_DIR = new URL("../../src/Command/Configuration/Definition/", import.meta.url);

/**
 * Os tipos de campo declarados nas definições, lidos do PHP: o quarto argumento do `$entry(…)`
 * e o `input:` nomeado. Falhar a encontrar um nome tira cobertura, nunca inventa um alarme.
 */
function declaredInputs() {
    const names = new Set();

    for (const file of readdirSync(DEFINITIONS_DIR).filter((name) => name.endsWith(".php"))) {
        const source = readFileSync(new URL(file, DEFINITIONS_DIR), "utf8");

        for (const [, type] of source.matchAll(/input:\s*'([^']+)'/g)) {
            names.add(type);
        }
        for (const [, type] of source.matchAll(
            /\$entry\(\s*'[^']*',\s*'[^']*',\s*'[^']*',\s*'([^']+)'/g,
        )) {
            names.add(type);
        }
        for (const [, type] of source.matchAll(
            /make\(\s*'[^']*',\s*\n\s*'[^']*',\s*\n\s*'[^']*',\s*\n\s*'([^']+)'/g,
        )) {
            names.add(type);
        }
    }

    return names;
}

test("as definições declaram tipos de campo que alguém desenha", () => {
    const declared = declaredInputs();
    assert.ok(declared.size > 10, "o leitor das definições não encontrou tipos suficientes");

    const orphans = [...declared].filter(
        (type) => !CONFIG_INPUTS[type] && type !== FALLBACK,
    );

    assert.deepEqual(orphans, [], "estes tipos caem no editor de JSON em cru");
});

test("o painel não tem tabela nenhuma a sobrepor o tipo declarado", () => {
    const source = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/config/catalog-model.js", import.meta.url),
        "utf8",
    );

    assert.doesNotMatch(source, /genericInputs/);
});

test("nenhum renderizador registado ficou sem desenhar nem sem ler", () => {
    for (const [name, descriptor] of Object.entries(CONFIG_INPUTS)) {
        assert.equal(typeof descriptor.read, "function", `${name} não sabe ler o que desenha`);

        const renders = typeof descriptor.render === "function" ||
            typeof descriptor.control === "function";
        assert.ok(renders || name === "action", `${name} não desenha nada e não é uma acção`);
    }
});
