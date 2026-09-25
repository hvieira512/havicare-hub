import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";

import "./support/browser-env.js";
import { deviceTypeIcon } from "../../src/Dashboard/dashboard/components/device-type-tiles.js";

/**
 * O ícone de cada tipo vem do catálogo, e não de uma tabela à parte no JavaScript: a entrada
 * desenha-o em PHP, que não lê módulos ES, e as duas cópias divergiam à primeira adição.
 */
const catalog = JSON.parse(
    readFileSync(new URL("../../config/device-types.json", import.meta.url), "utf8"),
);

test("todos os tipos do catálogo declaram um ícone", () => {
    const withoutIcon = Object.entries(catalog)
        .filter(([, descriptor]) => typeof descriptor.icon !== "string" || descriptor.icon === "")
        .map(([type]) => type);

    assert.deepEqual(withoutIcon, []);
});

test("o ícone que o mosaico desenha é o que o catálogo declara", () => {
    for (const [type, descriptor] of Object.entries(catalog)) {
        assert.equal(deviceTypeIcon(type), descriptor.icon, type);
    }
});

// Como em todo o resto do `domain.js`: o que não se reconhece cai no tipo por omissão, e não
// num ícone à parte que ninguém sabe ler.
test("um tipo que o catálogo não conhece cai no ícone do tipo por omissão", () => {
    assert.equal(deviceTypeIcon("maquina-do-cafe"), catalog.watch.icon);
});
