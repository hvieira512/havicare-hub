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
 * Os botões vivem tanto no JS que os constrói como nos templates PHP do modal, e varrer só um
 * dos lados faz passar por código morto um ouvinte cujo botão está do outro.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const root = (relative) => path.join(here, "../../src/Dashboard", relative);

/** No JS o selector leva as aspas escapadas; no PHP o atributo aparece em cru. */
const JS_SELECTOR = /\[data-action=\\"([\w-]+)\\"\]/g;

const filesUnder = (dir, extension) =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return filesUnder(full, extension);
        return entry.name.endsWith(extension) ? [full] : [];
    });

const read = (files) => files.map((file) => fs.readFileSync(file, "utf8")).join("\n");

const handlerSources = read([
    ...filesUnder(root("dashboard/settings"), ".js"),
    root("dashboard/wiring/settings.js"),
]);

const markupSources = read([
    ...filesUnder(root("components"), ".php"),
    root("index.php"),
]);

test("cada data-action que as definições procuram é escrito por quem desenha", () => {
    const wanted = [...handlerSources.matchAll(JS_SELECTOR)].map(([, action]) => action);
    assert.ok(wanted.length > 0, "nenhum selector encontrado -- a procura partiu-se");

    // Tirados os selectores, o que sobra do JS é só quem escreve; junta-se-lhe o PHP, que é
    // onde vive a maior parte dos botões do modal.
    const written = `${handlerSources.replace(JS_SELECTOR, "")}\n${markupSources}`;
    const orphans = [...new Set(wanted)].filter(
        (action) => !written.includes(`"${action}"`),
    );

    assert.deepEqual(orphans, [], "handlers à espera de um botão que ninguém escreve");
});

/**
 * O sentido inverso. Um `data-action` num botão que ninguém procura não parte nada -- o botão
 * até costuma funcionar, ligado pelo `id` --, mas anuncia um despacho delegado que não
 * existe, e quem o procurar não encontra do outro lado.
 *
 * Só se varre o JS que constrói a dashboard: os `data-action` escritos em `tests/` são
 * cenários, e não botões a sério.
 */
const PHP_ACTION = /data-action="([\w-]+)"/g;

test("cada data-action escrito no modal é procurado por algum handler", () => {
    const written = [...new Set([...markupSources.matchAll(PHP_ACTION)].map(([, action]) => action))];
    assert.ok(written.length > 0, "nenhum botão encontrado -- a procura partiu-se");

    const allHandlers = read(filesUnder(root("dashboard"), ".js"));
    const unread = written.filter((action) => !allHandlers.includes(`[data-action=\\"${action}\\"]`));

    assert.deepEqual(unread, [], "botões com um data-action que ninguém lê");
});
