import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { reachableFrom } from "./support/module-graph.js";

/**
 * Quem está parado no formulário de entrada não usa nada da dashboard, e descarregava-a
 * inteira na mesma: 92 módulos ES e 720 KB de JavaScript medidos contra o hub local, pagos
 * antes de haver sessão.
 *
 * O `app.js` entra por `import()`, e a carga arranca no clique do login -- em paralelo com o
 * pedido de autenticação, que é espera que já se estava a gastar.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const MAIN = path.join(here, "../../src/Dashboard/main.js");
const APP = path.join(here, "../../src/Dashboard/dashboard/app.js");

test("o ecrã de entrada não arrasta o grafo da dashboard", () => {
    const eager = reachableFrom([MAIN], { includeDynamic: false });

    assert.ok(
        !eager.has(APP),
        "o `app.js` não pode ser um import estático do `main.js`",
    );
    // O tecto é folgado de propósito: o que interessa é a ordem de grandeza -- a entrada
    // precisa do tema, da sessão e do relato de erros, e não das 92 peças da aplicação.
    assert.ok(
        eager.size <= 20,
        `o arranque carrega ${eager.size} módulos, e devia carregar um punhado`,
    );
});

test("o grafo da dashboard continua pendurado no main.js", () => {
    assert.ok(
        reachableFrom([MAIN]).has(APP),
        "seguindo o import() dinâmico, o app.js tem de continuar alcançável",
    );
});

test("o relato de erros instala-se antes de tudo", () => {
    const source = readFileSync(MAIN, "utf8");

    // Um erro no arranque só se apanha se o repórter já lá estiver; nem o `import()` do
    // `app.js` nem o `DOMContentLoaded` podem passar-lhe à frente.
    assert.ok(
        source.indexOf("installErrorReporting()") < source.indexOf("addEventListener"),
        "o installErrorReporting() corre antes de se ligar seja o que for",
    );
});
