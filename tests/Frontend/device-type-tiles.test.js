import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { deviceTypeTiles } from "../../src/Dashboard/dashboard/widgets.js";

/**
 * O mosaico de tipos de dispositivo, a devolver HTML em vez de o escrever no contentor.
 *
 * O `multiple` separa o filtro da escolha única e decide que atributos saem; as contagens são
 * opcionais, porque ao criar um modelo não há o que contar.
 */
const OPTIONS = ["watch", "radar", "gateway"];

const tiles = (options, extra = {}) => parseFragment(deviceTypeTiles(options, extra));

test("o tipo escolhido fica marcado", () => {
    const root = tiles(OPTIONS, { selected: "radar" });
    const [, radar] = root.querySelectorAll("button");

    assert.equal(radar.getAttribute("aria-pressed"), "true");
    assert.ok(radar.classList.contains("selected"));
});

test("sem contagens não sai pastilha de contagem", () => {
    const root = tiles(OPTIONS);

    assert.equal(root.querySelectorAll(".count-number").length, 0);
});

test("um tipo sem nenhum dispositivo e não escolhido não se pode carregar", () => {
    const root = tiles(OPTIONS, { counts: { watch: 3, radar: 0, gateway: 1 } });
    const [relogio, radar] = root.querySelectorAll("button");

    assert.equal(radar.disabled, true);
    assert.equal(relogio.disabled, false);
    assert.match(radar.textContent, /nenhum/);
});

test("em modo de filtro cada mosaico leva a chave e o valor do filtro", () => {
    const root = tiles(OPTIONS, { multiple: true, filterKey: "deviceType" });
    const primeiro = root.querySelector("button");

    assert.equal(primeiro.dataset.filterKey, "deviceType");
    assert.equal(primeiro.dataset.filterValue, "watch");
    assert.ok(root.querySelector(".device-type-tile-check"), "o modo múltiplo mostra a marca de visto");
});
