import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";

test("a tensão mostra a arritmia e o grupo de memória, e diz quando veio da memória", () => {
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
test("o ECG conta as amostras ao lado da amostragem", () => {
    assert.equal(
        uplinkCardContent("ecg", { heartRateBpm: 76, frequencyHz: 250, samples: [1, 2, 3] }).details,
        "Amostragem: 250 Hz · Amostras: 3",
    );
});

test("a temperatura diz onde foi medida", () => {
    assert.equal(uplinkCardContent("temperature", { bodyCelsius: 36.5, site: "forehead" }).details, "Local: Testa");
    assert.equal(uplinkCardContent("temperature", { bodyCelsius: 36.5, site: "ear" }).details, "Local: Ouvido");
});
