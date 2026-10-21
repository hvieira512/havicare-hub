import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";

import { deviceTypeIcon } from "../../src/Dashboard/dashboard/components/device-type-tiles.js";

/**
 * O ícone de cada tipo vem do catálogo, e não de uma tabela no JavaScript: a entrada desenha-o
 * em PHP, que não lê módulos ES.
 */
const catalog = JSON.parse(
    readFileSync(new URL("../../config/device-types.json", import.meta.url), "utf8"),
);

test("every catalog type declares an icon", () => {
    const withoutIcon = Object.entries(catalog)
        .filter(([, descriptor]) => typeof descriptor.icon !== "string" || descriptor.icon === "")
        .map(([type]) => type);

    assert.deepEqual(withoutIcon, []);
});

test("the tile draws the icon the catalog declares", () => {
    for (const [type, descriptor] of Object.entries(catalog)) {
        assert.equal(deviceTypeIcon(type), descriptor.icon, type);
    }
});

// Como em todo o resto do `domain.js`: o que não se reconhece cai no tipo por omissão, e não
// num ícone à parte que ninguém sabe ler.
test("a type the catalog does not know falls back to the default type icon", () => {
    assert.equal(deviceTypeIcon("maquina-do-cafe"), catalog.watch.icon);
});
