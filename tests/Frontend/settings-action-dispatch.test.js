import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * O despacho das definições é por `data-action`: um handler procura o botão pelo nome, e quem
 * desenha o botão escreve o mesmo nome. Os dois lados são texto solto, e um nome mal escrito
 * de um dos lados não dá erro nenhum -- o clique simplesmente não faz nada.
 *
 * Daí a verificação ser entre os dois: cada nome que um handler procura tem de ser escrito por
 * alguém. Uns saem em cru no HTML, outros vão como argumento ao `buttonGroup`, ao
 * `sectionStrip` ou ao `deviceTypeTiles`, e por isso o que se procura é a cadeia entre aspas e
 * não uma forma só.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const SETTINGS_ROOT = path.join(here, "../../src/Dashboard/dashboard/settings");

/** O nome como aparece num selector, que no código-fonte leva as aspas escapadas. */
const SELECTOR = /\[data-action=\\"([\w-]+)\\"\]/g;

const listModules = (dir) =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return listModules(full);
        return entry.name.endsWith(".js") ? [full] : [];
    });

const sources = listModules(SETTINGS_ROOT)
    .map((file) => fs.readFileSync(file, "utf8"))
    .join("\n");

test("cada data-action que as definições procuram é escrito por quem desenha", () => {
    const wanted = [...sources.matchAll(SELECTOR)].map(([, action]) => action);
    assert.ok(wanted.length > 0, "nenhum selector encontrado -- a procura partiu-se");

    // Sem os selectores, o que sobra é só quem escreve: um nome que só apareça no selector
    // não tem botão nenhum do outro lado.
    const written = sources.replace(SELECTOR, "");
    const orphans = [...new Set(wanted)].filter(
        (action) => !written.includes(`"${action}"`),
    );

    assert.deepEqual(orphans, []);
});
