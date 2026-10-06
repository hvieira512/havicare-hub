import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { reachableFrom } from "./support/module-graph.js";

/**
 * O separador «Configurações» são vinte e dois módulos ES e 209 KB, que só entram por `import()`
 * no `device-modal.js` quando alguém o abre.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const APP = path.join(here, "../../src/Dashboard/dashboard/app.js");
const CONFIG_ROOT = path.join(here, "../../src/Dashboard/dashboard/devices/config");
const PANEL = path.join(CONFIG_ROOT, "panel.js");

const configModulesIn = (files) =>
    [...files].filter((file) => file.startsWith(CONFIG_ROOT + path.sep));

/**
 * O `protocol-catalog.js` é de arranque: o `detail.js` lê-lhe o `protocolHelpCallPressModes`, e
 * isolar 2,7 KB custa mais do que rende.
 */
const EAGER_BY_DESIGN = ["protocol-catalog.js"];

test("o arranque da dashboard não arrasta o painel de configurações", () => {
    const eager = reachableFrom([APP], { includeDynamic: false });

    assert.ok(
        !eager.has(PANEL),
        "o `panel.js` não pode ser alcançável por imports estáticos a partir do `app.js`",
    );
    assert.deepEqual(
        configModulesIn(eager).map((file) => path.relative(CONFIG_ROOT, file)).sort(),
        EAGER_BY_DESIGN,
        "só o catálogo de protocolos pode sobrar do cluster no arranque",
    );
});

test("o painel de configurações continua pendurado no modal do dispositivo", () => {
    // Seguindo os `import()`, o cluster inteiro tem de continuar alcançável: um painel que
    // ninguém alcança é um separador que abre vazio.
    const full = reachableFrom([APP]);

    assert.ok(
        full.has(PANEL),
        "seguindo o import() dinâmico, o painel tem de continuar alcançável",
    );
    assert.equal(
        configModulesIn(full).length,
        22,
        "os vinte e dois módulos do cluster continuam a fazer parte do grafo",
    );
});
