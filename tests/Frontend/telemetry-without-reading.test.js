import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    hasReading,
    readingCountLabel,
    requestPill,
} from "../../src/Dashboard/dashboard/components/cards/request.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

state.capabilityCatalogByType.watch = [
    { key: "heart_rate", label: "Frequência cardíaca" },
    { key: "location", label: "Localização" },
    { key: "diaper_moisture", label: "Humidade da fralda" },
];
state.selectedDetail = { model: { deviceType: "watch" } };

const reading = (type, data = {}) => ({
    type,
    occurredAt: "2026-09-29T10:00:00Z",
    data,
});

// O que separa um mosaico de uma pastilha: uma capacidade declarada de que nunca chegou
// leitura nenhuma não ocupa um cartão a fingir que tem valor.
test("uma capacidade sem leitura nenhuma não tem leitura", () => {
    assert.equal(hasReading({ feature: "heart_rate" }, []), false);
    assert.equal(
        hasReading({ feature: "heart_rate" }, [reading("location")]),
        false,
    );
});

test("uma capacidade com leitura da sua categoria tem leitura", () => {
    assert.equal(
        hasReading({ feature: "heart_rate" }, [reading("heart_rate", { bpm: 62 })]),
        true,
    );
});

// O índice de humidade chega noutra mensagem e conta como leitura do mesmo mosaico.
test("a humidade da fralda conta as duas mensagens que a compõem", () => {
    assert.equal(
        hasReading({ feature: "diaper_moisture" }, [reading("diaper_moisture_level", { index: 40 })]),
        true,
    );
});

test("a pastilha nomeia a capacidade e leva o botão de pedir", () => {
    const pill = requestPill({ feature: "location", requestable: true }, false);

    assert.match(pill, /Localização/);
    assert.match(pill, /data-action="requestFeature"/);
    assert.match(pill, /data-feature="location"/);
    assert.match(pill, /Pedir/);
});

test("uma capacidade que não se pede não leva botão", () => {
    const pill = requestPill({ feature: "location", requestable: false }, false);

    assert.match(pill, /Localização/);
    assert.doesNotMatch(pill, /data-action/);
});

test("o botão da pastilha fica travado enquanto o pedido corre", () => {
    const pill = requestPill({ feature: "location", requestable: true }, true);

    assert.match(pill, /disabled/);
});

test("a cabeça da secção conta os dois lados", () => {
    assert.equal(readingCountLabel(5, 2), "5 com leitura · 2 sem");
    assert.equal(readingCountLabel(5, 0), "5 com leitura");
    assert.equal(readingCountLabel(0, 2), "2 sem leitura");
});
