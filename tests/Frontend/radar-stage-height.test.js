import test from "node:test";
import assert from "node:assert/strict";

import { stageHeightFor } from "../../src/Dashboard/dashboard/devices/radar-scene.js";

/**
 * A tela toma a proporção da divisão, mas não passa do que sobra do ecrã; apertada, a escala
 * uniforme encosta a planta ao meio e deixa faixas aos lados.
 */

const bounds = (width, height) => ({ minX: 0, minY: 0, width, height });

test("a room taller than wide does not exceed what is left of the screen", () => {
    // 2,0 × 2,6 m é a pior proporção da frota; 1580px é a tela num monitor largo.
    const height = stageHeightFor(bounds(20, 26), 1580, 700);

    assert.equal(height, 700);
});

test("a room that fits keeps the height of its proportion", () => {
    const height = stageHeightFor(bounds(60, 28), 800, 700);

    assert.equal(height, Math.round((800 - 60) * (28 / 60)) + 60);
    assert.ok(height < 700);
});

/** Num portátil de 14" sobram 316px para a tela, e a escala uniforme daria 235 de largura. */
test("where the screen falls short, the layout keeps a readable size", () => {
    assert.equal(stageHeightFor(bounds(20, 26), 887, 316), 420);
});

test("the comfort height is a floor for the cap, not an imposed height", () => {
    // Uma sala larga pede pouca altura, e continua a pedir pouca.
    assert.equal(stageHeightFor(bounds(60, 20), 887, 316), Math.round((887 - 60) / 3) + 60);
});

/** Sem chão, uma sala muito larga e pouco funda ficaria numa tira de trinta pixéis. */
test("a shallow room does not go below the minimum", () => {
    assert.equal(stageHeightFor(bounds(200, 5), 800, 700), 180);
});

test("without a width there is no height to write", () => {
    assert.equal(stageHeightFor(bounds(20, 26), 0, 700), null);
    assert.equal(stageHeightFor(bounds(0, 26), 1580, 700), null);
});
