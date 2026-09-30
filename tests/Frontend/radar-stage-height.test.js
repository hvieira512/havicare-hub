import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { stageHeightFor } from "../../src/Dashboard/dashboard/devices/radar-scene.js";

/**
 * A tela toma a proporção da divisão, mas não pode passar do que sobra do ecrã: num monitor
 * largo uma sala de 2,0 × 2,6 m pedia dois mil pixéis de altura e punha o diálogo a rolar.
 * Apertada, a escala uniforme encosta a planta ao meio e deixa faixas aos lados.
 */

const bounds = (width, height) => ({ minX: 0, minY: 0, width, height });

test("uma divisão mais alta do que larga não passa do que sobra do ecrã", () => {
    // 2,0 × 2,6 m é a pior proporção da frota; 1580px é a tela num monitor largo.
    const height = stageHeightFor(bounds(20, 26), 1580, 700);

    assert.equal(height, 700);
});

test("uma divisão que cabe fica com a altura da sua proporção", () => {
    const height = stageHeightFor(bounds(60, 28), 800, 700);

    assert.equal(height, Math.round((800 - 60) * (28 / 60)) + 60);
    assert.ok(height < 700);
});

/**
 * Num portátil de 14" sobram 316px para a tela, e a escala uniforme fazia disso um desenho de
 * 235 de largura numa tela de 887. A planta guarda a medida em que se lê, e o diálogo rola.
 */
test("onde o ecrã não chega, a planta guarda a medida em que se lê", () => {
    assert.equal(stageHeightFor(bounds(20, 26), 887, 316), 420);
});

test("o conforto é um chão do tecto, não uma altura imposta", () => {
    // Uma sala larga pede pouca altura, e continua a pedir pouca.
    assert.equal(stageHeightFor(bounds(60, 20), 887, 316), Math.round((887 - 60) / 3) + 60);
});

/** Sem chão, uma sala muito larga e pouco funda ficava numa tira de trinta pixéis. */
test("uma sala rasa não desce abaixo do mínimo", () => {
    assert.equal(stageHeightFor(bounds(200, 5), 800, 700), 180);
});

test("sem largura não há altura para escrever", () => {
    assert.equal(stageHeightFor(bounds(20, 26), 0, 700), null);
    assert.equal(stageHeightFor(bounds(0, 26), 1580, 700), null);
});
