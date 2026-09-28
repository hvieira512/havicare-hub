import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * O ECG e a PPG diziam «Dados de ECG» e «Dados de PPG» com os números já dentro da mensagem.
 * O exame traz a frequência que apurou, e a onda traz o tamanho do lote.
 *
 * A contagem lê-se do `sampleCount`: o `VeepooBridge::forDashboard()` troca as amostras por
 * ela antes de guardar, porque um traçado são dezasseis mil e o histórico tem cem entradas.
 */
test("o cartão de ECG mostra a frequência que o exame apurou", () => {
    const card = uplinkCardContent("ecg", {
        heartRateBpm: 68,
        qtcMilliseconds: 410,
        sampleCount: 16000,
    });

    assert.equal(card.value, "68 bpm");
    assert.match(card.details, /410/);
});

test("sem frequência apurada o ECG conta as amostras que o histórico guardou", () => {
    assert.equal(uplinkCardContent("ecg", { sampleCount: 1200 }).value, "1200 amostras");
});

test("a PPG conta o lote, e di-lo quando não há nenhum", () => {
    assert.equal(uplinkCardContent("ppg", { sampleCount: 512 }).value, "512 amostras");
    assert.equal(uplinkCardContent("ppg", {}).value, "Sem amostras");
});

/** Quem não passa pelo `forDashboard` ainda traz as amostras inteiras. */
test("uma onda com as amostras à vista conta-as na mesma", () => {
    assert.equal(uplinkCardContent("ppg", { samples: [1, 2, 3] }).value, "3 amostras");
});
