import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { commandError, commandLabel, fieldLabel } from "../../src/Dashboard/dashboard/format.js";

/**
 * Os cartões do sono. A noite chega inteira -- fronteiras, duração e os troços de cada fase --
 * e o ecrã mostrava "Dados de sono": um anúncio de que há dados, com os dados ao lado.
 */

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

test("a noite mostra quanto se dormiu, e não um texto fixo", () => {
    assert.equal(uplinkCardContent("sleep", NIGHT).value, "6h 39m");
});

test("os detalhes dizem o tempo de cada fase, que é o que a noite tem de próprio", () => {
    const details = uplinkCardContent("sleep", NIGHT).details;

    assert.match(details, /Profundo: 9 min/);
    assert.match(details, /Leve: 52 min/);
    assert.match(details, /REM: 13 min/);
    assert.match(details, /3 troços/);
});

test("sem instantes de confiança a noite continua a dizer as durações", () => {
    // `timingValid: false` tira as fronteiras e deixa os troços de pé -- é o contrato.
    const card = uplinkCardContent("sleep", {
        totalDurationMinutes: 120,
        timingValid: false,
        segments: [{ durationMinutes: 120, type: "light_sleep" }],
    });

    assert.equal(card.value, "2h 0m");
    assert.doesNotMatch(card.details, /Invalid Date|NaN|undefined/);
});

test("as estrelas dizem de quantas são: um 3 sozinho não se sabe ler", () => {
    const card = uplinkCardContent("sleep_quality", { qualityStars: 3, efficiencyScore: 4 });

    assert.equal(card.value, "3 de 5 estrelas");
});

test("as pontuações aparecem em português, e não com o nome do campo", () => {
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

test("os pedidos da pulseira não ficam em inglês no ecrã", () => {
    assert.equal(commandLabel({ label: "Sleep" }), "Sono");
    assert.equal(commandLabel({ label: "Daily totals" }), "Totais do dia");
    assert.equal(commandLabel({ label: "Body composition" }), "Composição corporal");
    assert.equal(commandLabel({ label: "Firmware version" }), "Versão de firmware");
    assert.equal(commandLabel({ label: "Device status" }), "Estado do dispositivo");
});

test("os lípidos no sangue não mostram o nome do campo em inglês", () => {
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

test("a versão de firmware não se anuncia em inglês", () => {
    assert.equal(fieldLabel("version"), "Versão");
});

test("a razão de um pedido falhado diz-se em português", () => {
    assert.equal(commandError("no_response"), "O aparelho não respondeu");
    assert.equal(commandError("not_worn"), "A pulseira não estava ao pulso");
    assert.equal(commandError("response_timeout"), "Sem resposta dentro do prazo");
    // Um código novo do hub não se esconde: aparece como veio, para se poder procurar.
    assert.equal(commandError("motivo_novo"), "motivo_novo");
    assert.equal(commandError(""), "");
});
