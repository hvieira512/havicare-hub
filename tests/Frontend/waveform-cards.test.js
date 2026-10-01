import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";

/** A contagem lê-se do `sampleCount`, que é o que o histórico guarda no lugar das amostras. */
test("o cartão de ECG mostra a frequência que o exame apurou", () => {
    const card = uplinkCardContent("ecg", {
        heartRateBpm: 68,
        qtcMilliseconds: 410,
        hrvMilliseconds: 35,
        frequencyHz: 250,
        sampleCount: 16000,
    });

    assert.equal(card.value, "68 bpm");
    assert.equal(card.details, "QTc: 410 ms · VFC: 35 ms · Amostragem: 250 Hz");
});

/** Nada aparece em inglês na dashboard, e os detalhes destes dois são nomes de contrato. */
test("os campos do exame saem traduzidos e com unidade", () => {
    const details = uplinkCardContent("ppg", { sampleCount: 512, frequencyHz: 100 }).details;

    assert.equal(details, "Amostragem: 100 Hz");
    assert.doesNotMatch(details, /[A-Z][a-z]+[A-Z]/, "sem nomes em camelCase à vista");
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
