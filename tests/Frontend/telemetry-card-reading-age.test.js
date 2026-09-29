import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { requestCardShell } from "../../src/Dashboard/dashboard/components/cards/request.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

state.capabilityCatalogByType.watch = [
    { key: "heart_rate", label: "Frequência cardíaca" },
];
state.selectedDetail = { model: { deviceType: "watch" } };

const minutesAgo = (minutes) =>
    new Date(Date.now() - minutes * 60_000).toISOString();

const heartRateCard = (telemetry) => requestCardShell(
    { feature: "heart_rate", requestable: true },
    false,
    telemetry,
);

// A idade da leitura é a pergunta seguinte a «quanto?»: sem ela o cartão não distingue uma
// medição de agora de uma da semana passada.
test("a card with a reading shows how long ago it was taken", () => {
    const html = heartRateCard([
        { type: "heart_rate", occurredAt: minutesAgo(4), data: { bpm: 62 } },
    ]);

    assert.match(html, /62 bpm/);
    assert.match(html, /há 4m/);
});

test("a card without any reading says nothing about age", () => {
    const html = heartRateCard([]);

    assert.doesNotMatch(html, /há /);
    assert.doesNotMatch(html, /nunca/);
});
