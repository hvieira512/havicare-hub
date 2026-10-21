import test from "node:test";
import assert from "node:assert/strict";

import { cardContent as uplinkCardContent } from "./support/cards.js";

/** A contagem lê-se do `sampleCount`, que é o que o histórico guarda no lugar das amostras. */
test("the ECG card shows the rate the exam measured", () => {
    const card = uplinkCardContent("ecg", {
        heartRateBpm: 68,
        qtcMilliseconds: 410,
        hrvMilliseconds: 35,
        frequencyHz: 250,
        sampleCount: 16000,
    });

    assert.equal(card.value, "68 bpm");
    assert.equal(card.details, "QTc: 410 ms · VFC: 35 ms · Amostragem: 250 Hz · Amostras: 16000");
});

/** Nada aparece em inglês na dashboard, e os detalhes destes dois são nomes de contrato. */
test("the exam fields show translated and with units", () => {
    const details = uplinkCardContent("ppg", { sampleCount: 512, frequencyHz: 100 }).details;

    assert.equal(details, "Amostragem: 100 Hz");
    assert.doesNotMatch(details, /[A-Z][a-z]+[A-Z]/, "sem nomes em camelCase à vista");
});

test("without a measured rate the ECG counts the samples history kept", () => {
    assert.equal(uplinkCardContent("ecg", { sampleCount: 1200 }).value, "1200 amostras");
});

test("the PPG counts the batch, and says so when there is none", () => {
    assert.equal(uplinkCardContent("ppg", { sampleCount: 512 }).value, "512 amostras");
    assert.equal(uplinkCardContent("ppg", {}).value, "Sem amostras");
});

/** Quem não passa pelo `forDashboard` ainda traz as amostras inteiras. */
test("a waveform with visible samples still counts them", () => {
    assert.equal(uplinkCardContent("ppg", { samples: [1, 2, 3] }).value, "3 amostras");
});
