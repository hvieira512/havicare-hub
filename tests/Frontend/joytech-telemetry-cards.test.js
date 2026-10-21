import test from "node:test";
import assert from "node:assert/strict";

import { cardContent as uplinkCardContent } from "./support/cards.js";

test("blood pressure shows arrhythmia and the memory group, and says when it came from memory", () => {
    const data = { systolicMmHg: 119, diastolicMmHg: 86, irregularHeartbeat: false, memoryGroup: 1 };

    assert.equal(
        uplinkCardContent("blood_pressure", data).details,
        "Batimento irregular: Não · Grupo de memória: 1",
    );
    assert.equal(
        uplinkCardContent("blood_pressure", { ...data, fromHistory: true }).details,
        "Batimento irregular: Não · Grupo de memória: 1 · Da memória: Sim",
    );
});

/** O tensiómetro guarda as amostras inteiras; a pulseira só a contagem. */
test("the ECG counts the samples next to the sample rate", () => {
    assert.equal(
        uplinkCardContent("ecg", { heartRateBpm: 76, frequencyHz: 250, samples: [1, 2, 3] }).details,
        "Amostragem: 250 Hz · Amostras: 3",
    );
});

test("temperature says where it was measured", () => {
    assert.equal(uplinkCardContent("temperature", { bodyCelsius: 36.5, site: "forehead" }).details, "Local: Testa");
    assert.equal(uplinkCardContent("temperature", { bodyCelsius: 36.5, site: "ear" }).details, "Local: Ouvido");
});
