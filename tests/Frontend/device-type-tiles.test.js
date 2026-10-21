import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { deviceTypeTiles } from "../../src/Dashboard/dashboard/components/device-type-tiles.js";

/**
 * O `multiple` separa o filtro da escolha única; as contagens são opcionais, porque ao criar um
 * modelo não há o que contar.
 */
const OPTIONS = ["watch", "radar", "gateway"];

const tiles = (options, extra = {}) => parseFragment(deviceTypeTiles(options, extra));

test("the chosen type is marked", () => {
    const root = tiles(OPTIONS, { selected: "radar" });
    const [, radar] = root.querySelectorAll("button");

    assert.equal(radar.getAttribute("aria-pressed"), "true");
    assert.ok(radar.classList.contains("selected"));
});

test("without counts there is no count badge", () => {
    const root = tiles(OPTIONS);

    assert.equal(root.querySelectorAll(".count-number").length, 0);
});

test("a type with no devices that is not chosen cannot be pressed", () => {
    const root = tiles(OPTIONS, { counts: { watch: 3, radar: 0, gateway: 1 } });
    const [watch, radar] = root.querySelectorAll("button");

    assert.equal(radar.disabled, true);
    assert.equal(watch.disabled, false);
    assert.match(radar.textContent, /nenhum/);
});

test("in filter mode each tile carries the filter key and value", () => {
    const root = tiles(OPTIONS, { multiple: true, filterKey: "deviceType" });
    const firstTile = root.querySelector("button");

    assert.equal(firstTile.dataset.filterKey, "deviceType");
    assert.equal(firstTile.dataset.filterValue, "watch");
    assert.ok(root.querySelector(".device-type-tile-check"), "o modo múltiplo mostra a marca de visto");
});
