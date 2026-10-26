import test from "node:test";
import assert from "node:assert/strict";

import { cardContent as uplinkCardContent } from "./support/cards.js";
import { commandError } from "../../src/Dashboard/dashboard/format.js";

/** A noite chega inteira -- fronteiras, duração e os troços de cada fase -- e o cartão mostra-a. */

const NIGHT = {
    startTime: "2026-09-16T00:11:00Z",
    endTime: "2026-09-16T06:50:00Z",
    totalDurationMinutes: 399,
    timingValid: true,
    segments: [
        { startTime: "2026-09-16T00:11:00Z", endTime: "2026-09-16T01:03:00Z", durationMinutes: 52, type: "light_sleep" },
        { startTime: "2026-09-16T01:03:00Z", endTime: "2026-09-16T01:12:00Z", durationMinutes: 9, type: "deep_sleep" },
        { startTime: "2026-09-16T01:12:00Z", endTime: "2026-09-16T01:25:00Z", durationMinutes: 13, type: "rem" },
    ],
};

test("the night shows how long was slept, not fixed text", () => {
    assert.equal(uplinkCardContent("sleep", NIGHT).value, "6 h 39 min");
});

test("the details give each stage's time, which is what the night card adds", () => {
    const details = uplinkCardContent("sleep", NIGHT).details;

    assert.match(details, /Profundo: 9 min/);
    assert.match(details, /Leve: 52 min/);
    assert.match(details, /REM: 13 min/);
    assert.match(details, /3 troços/);
});

test("without trusted timestamps the night still shows the durations", () => {
    // `timingValid: false` tira as fronteiras e deixa os troços de pé -- é o contrato.
    const card = uplinkCardContent("sleep", {
        totalDurationMinutes: 120,
        timingValid: false,
        segments: [{ durationMinutes: 120, type: "light_sleep" }],
    });

    assert.equal(card.value, "2 h");
    assert.doesNotMatch(card.details, /Invalid Date|NaN|undefined/);
});

test("stars say out of how many: a lone 3 cannot be read", () => {
    const card = uplinkCardContent("sleep_quality", { qualityStars: 3, efficiencyScore: 4 });

    assert.equal(card.value, "3 de 5 estrelas");
});

test("scores show in Portuguese, not with the field name", () => {
    const details = uplinkCardContent("sleep_quality", {
        qualityStars: 3,
        deepSleepScore: 0,
        efficiencyScore: 4,
        awakeningCount: 0,
        nightAwakeMinutes: 0,
    }).details;

    assert.doesNotMatch(details, /DeepSleepScore|QualityStars|Awakening/);
    assert.match(details, /Pontuação do sono profundo: 0/);
    assert.match(details, /Despertares: 0/);
});

test("blood lipids do not show the English field name", () => {
    const details = uplinkCardContent("blood_lipids", {
        totalCholesterolMmolPerL: 4.3,
        triglyceridesMmolPerL: 1.03,
        hdlMmolPerL: 1.05,
        ldlMmolPerL: 2.52,
    }).details;

    assert.doesNotMatch(details, /MmolPerL/);
    assert.match(details, /Triglicéridos: 1.03 mmol\/L/);
    assert.match(details, /HDL: 1.05 mmol\/L/);
});

test("a failed request's reason is said in Portuguese", () => {
    assert.equal(commandError("no_response"), "O aparelho não respondeu");
    assert.equal(commandError("not_worn"), "A pulseira não estava ao pulso");
    assert.equal(commandError("response_timeout"), "Sem resposta dentro do prazo");
    assert.equal(commandError("disconnected"), "O aparelho desligou-se do gateway");
    assert.equal(commandError("not_connected"), "O aparelho não estava ligado ao gateway");
    assert.equal(commandError("await_timeout"), "O aparelho não respondeu a tempo");
    assert.equal(commandError("write_failed"), "O gateway não conseguiu escrever no aparelho");
    // Um código novo do hub não se esconde: aparece como veio, para se poder procurar.
    assert.equal(commandError("motivo_novo"), "motivo_novo");
    assert.equal(commandError(""), "");
});
