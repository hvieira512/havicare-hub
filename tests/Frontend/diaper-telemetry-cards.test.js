import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o nome de uma capacidade vem do catalogo, e
// esse caminho passa pelo api/http.js, que toca em window ao carregar.
import "./support/browser-env.js";
import {renderRequestCardShell} from "../../src/Dashboard/dashboard/telemetry-cards.js";
import {state} from "../../src/Dashboard/dashboard/state.js";

// O nome de uma capacidade vem do catálogo do tipo do dispositivo escolhido, e não de um
// mapa escrito no frontend. Um cartão desenhado sem catálogo mostra a chave humanizada,
// por isso o teste põe o medidor de fraldas em cima da mesa antes de desenhar.
state.capabilityCatalogByType.diaper_sensor = [
    {key: "diaper_moisture", label: "Humidade da fralda"},
    {key: "diaper_moisture_level", label: "Nível de humidade"},
    {key: "diaper_condition", label: "Estado da fralda"},
];
state.selectedDetail = {model: {deviceType: "diaper_sensor"}};

const channel = (index, delta, baseline = 1) => ({
    index,
    baseline,
    value: baseline + delta,
    delta,
});

const moistureCard = (data) => renderRequestCardShell(
    {feature: "diaper_moisture", requestable: false},
    false,
    [{type: "diaper_moisture", occurredAt: "2026-08-06T13:00:00Z", data}],
);

test("MONIT condition renders as a status card without a request button", () => {
    const html = renderRequestCardShell(
        {feature: "diaper_condition", requestable: false},
        false,
        [{type: "diaper_condition", occurredAt: "2026-08-06T13:00:00Z", data: {state: "clean"}}],
    );

    assert.match(html, /Fralda limpa/);
    assert.match(html, /fa-baby/);
    assert.doesNotMatch(html, /data-action="requestFeature"/);
});

test("MONIT moisture takes the full row and renders one column per channel", () => {
    const html = moistureCard({
        channels: [channel(1, 0), channel(2, 5), channel(3, 28)],
        affectedChannelCount: 1,
        maximumDelta: 28,
    });

    assert.match(html, /col-12 col-md-12/);
    assert.equal(html.match(/class="diaper-channel"/g).length, 3);
    assert.match(html, /fa-droplet/);
    assert.doesNotMatch(html, /data-action="requestFeature"/);
});

test("MONIT moisture bands each channel against the normalizer thresholds", () => {
    // 3 is dry (<4), 4 and 11 are damp, 12 is the affected threshold.
    const html = moistureCard({
        channels: [channel(1, 3), channel(2, 4), channel(3, 11), channel(4, 12)],
        affectedChannelCount: 1,
        maximumDelta: 12,
    });

    const bands = [...html.matchAll(/diaper-channel-fill diaper-channel-fill--(\w+)/g)]
        .map((match) => match[1]);
    assert.deepEqual(bands, ["dry", "damp", "damp", "wet"]);
});

test("MONIT moisture scales bar height to twice the threshold and clamps above it", () => {
    const html = moistureCard({
        channels: [channel(1, 0), channel(2, 12), channel(3, 24), channel(4, 63)],
        affectedChannelCount: 3,
        maximumDelta: 63,
    });

    const heights = [...html.matchAll(/style="height:([\d.]+)%"/g)]
        .map((match) => Number(match[1]));
    assert.deepEqual(heights, [0, 50, 100, 100]);
});

test("MONIT moisture summarises the maximum delta and affected channel count", () => {
    const html = moistureCard({
        channels: [channel(1, 2), channel(2, 31), channel(3, 16)],
        affectedChannelCount: 2,
        maximumDelta: 31,
    });

    assert.match(html, /Máx\. <strong class="text-body">31<\/strong>/);
    assert.match(html, /<strong class="text-body">2<\/strong> de 3 canais acima do limiar \(12\)/);
});

test("MONIT moisture takes the thresholds from the reading, not from a constant", () => {
    // Preset alto: um canal conta como molhado a partir de 7 e bastam 3 para mudar.
    // Nada disto se pode escrever no cartão -- é configurável por sensor.
    const html = moistureCard({
        channels: [channel(1, 1), channel(2, 2), channel(3, 6), channel(4, 7), channel(5, 20)],
        affectedChannelCount: 2,
        maximumDelta: 20,
        requiredChannelCount: 3,
        wetDelta: 7,
    });

    const bands = [...html.matchAll(/diaper-channel-fill diaper-channel-fill--(\w+)/g)]
        .map((match) => match[1]);
    // Seco é abaixo de `cleanMaxDelta` = intdiv(7, 4) + 1 = 2, como no normalizador.
    assert.deepEqual(bands, ["dry", "damp", "damp", "wet", "wet"]);

    // A escala é o dobro do limiar, logo o canal que acabou de molhar fica a meia altura
    // e a marca de muda com ele -- em 7, e não nos 50% de um limiar de 12.
    const heights = [...html.matchAll(/style="height:([\d.]+)%"/g)]
        .map((match) => Number(match[1]));
    assert.equal(heights[3], 50);
    assert.equal(heights[4], 100);
    assert.ok(heights.slice(0, 3).every((height) => height < 50));
    assert.match(html, /--diaper-threshold:50%/);

    assert.match(html, /<strong class="text-body">2<\/strong> de 3 canais acima do limiar \(7\)/);
});

test("MONIT moisture falls back to the normal preset for readings stored before the thresholds travelled", () => {
    const html = moistureCard({
        channels: [channel(1, 3), channel(2, 12)],
        affectedChannelCount: 1,
        maximumDelta: 12,
    });

    const bands = [...html.matchAll(/diaper-channel-fill diaper-channel-fill--(\w+)/g)]
        .map((match) => match[1]);
    assert.deepEqual(bands, ["dry", "wet"]);
    assert.match(html, /de 2 canais acima do limiar \(12\)/);
});

test("MONIT moisture exposes the baseline and raw reading per channel", () => {
    const html = moistureCard({
        channels: [channel(5, 3, 32)],
        affectedChannelCount: 0,
        maximumDelta: 3,
    });

    // Baselines differ wildly between channels, so the raw reading is only
    // meaningful next to its baseline.
    assert.match(html, /Canal 5 · delta 3 \(base 32, leitura 35\)/);
});

test("MONIT moisture shows the level index as its value, from the message that carries it", () => {
    // O índice é capacidade própria e chega menos vezes do que os canais: o cartão junta a
    // leitura mais recente de cada tipo, senão a dos canais apagava o número.
    const html = renderRequestCardShell(
        {feature: "diaper_moisture", requestable: false},
        false,
        [
            {
                type: "diaper_moisture",
                occurredAt: "2026-08-06T13:05:00Z",
                data: {channels: [channel(1, 12)], affectedChannelCount: 1, maximumDelta: 12},
            },
            {
                type: "diaper_moisture_level",
                occurredAt: "2026-08-06T13:00:00Z",
                data: {index: 29, alertIndex: 40},
            },
        ],
    );

    assert.match(html, />29%</);
    assert.match(html, /diaper-strip/);
    // A barra de 0 a 100 e a marca de alerta desapareceram com o segundo cartão.
    assert.doesNotMatch(html, /diaper-level/);
    assert.doesNotMatch(html, /alerta a partir de/);
});

test("MONIT moisture degrades to a plain card when no channels are reported", () => {
    const html = moistureCard({affectedChannelCount: 0, maximumDelta: 0});

    assert.match(html, /Humidade da fralda/);
    assert.doesNotMatch(html, /diaper-strip/);
});
