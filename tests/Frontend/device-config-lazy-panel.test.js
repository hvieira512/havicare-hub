import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { reachableFrom } from "./support/module-graph.js";

/**
 * O separador «Configurações» do modal de um dispositivo é servido por vinte módulos ES e
 * 206 KB, e não é tocado até alguém abrir um dispositivo **e** clicar nesse separador. Eram
 * 29% dos bytes do arranque, pagos por toda a gente para servir uma minoria das visitas.
 *
 * Entra por `import()` no `device-modal.js`, pedido pelo gancho do separador.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const APP = path.join(here, "../../src/Dashboard/dashboard/app.js");
const CONFIG_ROOT = path.join(here, "../../src/Dashboard/dashboard/devices/config");
const PANEL = path.join(CONFIG_ROOT, "panel.js");

const configModulesIn = (files) =>
    [...files].filter((file) => file.startsWith(CONFIG_ROOT + path.sep));

/**
 * O `protocol-catalog.js` fica de fora da regra por uma razão nomeada: o `detail.js` lê-lhe o
 * `protocolHelpCallPressModes` para desenhar o detalhe de um dispositivo, que é ecrã de
 * arranque. É um módulo e 2,7 KB -- isolá-lo do resto do cluster custava mais do que rende.
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
        20,
        "os vinte módulos do cluster continuam a fazer parte do grafo",
    );
});
