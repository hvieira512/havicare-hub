import test from "node:test";
import assert from "node:assert/strict";

import { requestCardShell as buildCard } from "../../src/Dashboard/dashboard/components/cards/request.js";
import { state } from "../../src/Dashboard/dashboard/core/state.js";
import { asText, parseFragment } from "./support/dom.js";

const requestCardShell = asText(buildCard);
const textOf = (html) => parseFragment(html).textContent.replace(/\s+/g, " ");

// O nome de uma capacidade vem do catálogo do tipo escolhido: sem ele o cartão mostra a
// chave humanizada.
state.capabilityCatalogByType.diaper_sensor = [
    { key: "diaper_moisture", label: "Humidade da fralda" },
    { key: "diaper_moisture_level", label: "Nível de humidade" },
    { key: "diaper_condition", label: "Estado da fralda" },
];
state.selectedDetail = { model: { deviceType: "diaper_sensor" } };

const channel = (index, delta, baseline = 1) => ({
    index,
    baseline,
    value: baseline + delta,
    delta,
});

const moistureCard = (data) => requestCardShell(
    { feature: "diaper_moisture", requestable: false },
    false,
    [{ type: "diaper_moisture", occurredAt: "2026-08-06T13:00:00Z", data }],
);

test("MONIT condition renders as a status card without a request button", () => {
    const html = requestCardShell(
        { feature: "diaper_condition", requestable: false },
        false,
        [{ type: "diaper_condition", occurredAt: "2026-08-06T13:00:00Z", data: { state: "clean" } }],
    );

    assert.match(html, /Fralda limpa/);
    assert.doesNotMatch(html, /data-action="requestFeature"/);
});

const bandsOf = (html) => [...html.matchAll(/diaper-channel-fill diaper-channel-fill--(\w+)/g)]
    .map((match) => match[1]);

test("MONIT moisture paints each channel with the state the hub computed", () => {
    // O 5 com `wet` prova que a tira não refaz a conta a partir do delta.
    const html = moistureCard({
        channels: [
            { ...channel(1, 3), state: "dry" },
            { ...channel(2, 4), state: "damp" },
            { ...channel(3, 5), state: "wet" },
        ],
        affectedChannelCount: 1,
        maximumDelta: 5,
        requiredChannelCount: 3,
        wetDelta: 7,
    });

    assert.deepEqual(bandsOf(html), ["dry", "damp", "wet"]);
    assert.match(textOf(html), /1 de 3 canais acima do limiar \(7\)/);
});

test("MONIT moisture falls back to the normal preset for readings stored before the thresholds travelled", () => {
    // Sem estado por canal, a tira não pinta nenhum.
    const html = moistureCard({
        channels: [channel(1, 3), channel(2, 12)],
        affectedChannelCount: 1,
        maximumDelta: 12,
    });

    assert.deepEqual(bandsOf(html), ["dry", "dry"]);
    assert.match(html, /de 2 canais acima do limiar \(12\)/);
});

test("MONIT moisture exposes the baseline and raw reading per channel", () => {
    const html = moistureCard({
        channels: [channel(5, 3, 32)],
        affectedChannelCount: 0,
        maximumDelta: 3,
    });

    // As bases diferem muito entre canais, e por isso a leitura crua só tem sentido ao lado
    // da base.
    assert.match(html, /Canal 5 · delta 3 \(base 32, leitura 35\)/);
});

test("MONIT moisture shows the level index as its value, from the message that carries it", () => {
    // O índice é capacidade própria e chega menos vezes do que os canais: o cartão junta a
    // leitura mais recente de cada tipo, senão a dos canais apagava o número.
    const html = requestCardShell(
        { feature: "diaper_moisture", requestable: false },
        false,
        [
            {
                type: "diaper_moisture",
                occurredAt: "2026-08-06T13:05:00Z",
                data: { channels: [channel(1, 12)], affectedChannelCount: 1, maximumDelta: 12 },
            },
            {
                type: "diaper_moisture_level",
                occurredAt: "2026-08-06T13:00:00Z",
                data: { index: 29, alertIndex: 40 },
            },
        ],
    );

    assert.match(html, />29%</);
    assert.match(html, /diaper-strip/);
    assert.doesNotMatch(html, /diaper-level/);
    assert.doesNotMatch(html, /alerta a partir de/);
});

test("MONIT moisture degrades to a plain card when no channels are reported", () => {
    const html = moistureCard({ affectedChannelCount: 0, maximumDelta: 0 });

    assert.match(html, /Humidade da fralda/);
    assert.doesNotMatch(html, /diaper-strip/);
});
