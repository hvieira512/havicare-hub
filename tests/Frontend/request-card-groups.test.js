import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";

const { telemetryRequestCards, renderRequestCardGroup } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

/**
 * Os cartões de "Pedir dados" separam-se em dois grupos: a telemetria, que o dispositivo
 * mede, e a informação do sistema, que ele diz sobre si próprio. São duas coisas de natureza
 * diferente e por isso não se misturam na mesma grelha.
 */

const supported = (requestable = true) => ({ supported: true, requestable });

test("um dispositivo só com telemetria dá um grupo, e um radar dá os dois", () => {
    const [telemetry] = telemetryRequestCards({
        heart_rate: supported(),
        battery: supported(),
    });
    assert.equal(telemetry.label, "Telemetria");

    const groups = telemetryRequestCards({
        heart_rate: supported(),
        firmware_version: supported(),
        device_status: supported(),
    });
    assert.deepEqual(
        groups.map((group) => group.label),
        ["Telemetria", "Informação do sistema"],
    );
    assert.deepEqual(
        groups.map((group) => group.cards.map((card) => card.feature)),
        [["heart_rate"], ["device_status", "firmware_version"]],
    );
});

test("uma capacidade que o modelo não tem não dá cartão", () => {
    const groups = telemetryRequestCards({
        heart_rate: { supported: false, requestable: true },
        battery: supported(),
    });

    assert.deepEqual(
        groups.flatMap((group) => group.cards.map((card) => card.feature)),
        ["battery"],
    );
});

test("a faixa com o nome do grupo só existe quando há mais do que um grupo", () => {
    const [group] = telemetryRequestCards({ heart_rate: supported() });

    // Num relógio, que só tem "Telemetria", a faixa era uma moldura dentro de um cartão que
    // já se chama "Pedir dados".
    const alone = renderRequestCardGroup(group, [], false, []);
    assert.doesNotMatch(alone, /Telemetria/);
    assert.doesNotMatch(alone, /section-label/);

    const accompanied = renderRequestCardGroup(group, [], true, []);
    assert.match(accompanied, /section-label[^>]*>Telemetria</);
    assert.match(accompanied, /count-chip">1</);
});

/**
 * Um mosaico sem leitura fica com o ícone e o título e mais nada. O lugar do valor vazio, ao
 * lado dos irmãos que têm um, já se lê como ausência de leitura, e uma etiqueta a dizê-lo
 * repetia o que o vazio diz -- multiplicada pelos mosaicos vazios que o ecrã tiver.
 */
test("um mosaico sem leitura não leva etiqueta a dizê-lo", () => {
    const [group] = telemetryRequestCards({ heart_rate: supported() });

    const html = renderRequestCardGroup(group, [], false, []);

    // O catálogo de capacidades não está carregado aqui, e por isso o nome vem do
    // `humanizeCapabilityKey`. O que importa é que só o nome lá está.
    assert.match(html, /telemetry-card-title">Heart Rate</);
    assert.doesNotMatch(html, /telemetry-card-value/);
    assert.doesNotMatch(html, /telemetry-row-details/);
});

test("com leitura, o mosaico mostra o valor", () => {
    const [group] = telemetryRequestCards({ heart_rate: supported() });
    const reading = {
        type: "heart_rate",
        occurredAt: "2026-08-25T10:15:00Z",
        data: { bpm: 72 },
    };

    const html = renderRequestCardGroup(group, [reading], false, []);

    assert.match(html, /72 bpm/);
    assert.match(html, /telemetry-card-value/);
});
