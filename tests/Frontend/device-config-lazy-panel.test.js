import test from "node:test";
import assert from "node:assert/strict";

import path from "node:path";
import { fileURLToPath } from "node:url";

import { reachableFrom } from "./support/module-graph.js";

/**
 * O separador «Configurações» são duas dezenas de módulos ES e 209 KB, que só entram por `import()`
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
