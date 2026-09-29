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
    });
    assert.deepEqual(
        groups.map((group) => group.label),
        ["Telemetria", "Informação do sistema"],
    );
    assert.deepEqual(
        groups.map((group) => group.cards.map((card) => card.feature)),
        [["heart_rate"], ["firmware_version"]],
    );
});

/**
 * O que o aparelho diz sobre si próprio fica junto: a versão do firmware e o pedido para ele
 * reler o seu estado são a mesma natureza, e nenhuma das duas é uma medição do mundo.
 */
test("o estado do dispositivo fica com a versão do firmware, e não entre as medições", () => {
    const groups = telemetryRequestCards({
        battery: supported(false),
        device_status: supported(),
        firmware_version: supported(),
    });

    assert.deepEqual(
        groups.map((group) => [group.label, group.cards.map((card) => card.feature)]),
        [
            ["Telemetria", ["battery"]],
            ["Informação do sistema", ["device_status", "firmware_version"]],
        ],
    );
});

/**
 * A proximidade é a força com que cada gateway ouve o aparelho, e o resumo já a mostra em
 * «Dispositivos ligados» -- uma linha por gateway, com barras. O mosaico dizia-a pior: um só,
 * e sem nomear o gateway que a produziu.
 */
test("a proximidade não dá mosaico, que o resumo já a mostra por gateway", () => {
    const groups = telemetryRequestCards({
        battery: supported(false),
        proximity: supported(false),
    });

    assert.deepEqual(
        groups.flatMap((group) => group.cards.map((card) => card.feature)),
        ["battery"],
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
    const reading = {
        type: "heart_rate",
        occurredAt: "2026-08-25T10:15:00Z",
        data: { bpm: 72 },
    };

    // Num relógio, que só tem "Telemetria", a faixa seria uma moldura dentro de um cartão que
    // já se chama "Pedir dados".
    const alone = renderRequestCardGroup(group, [reading], false, []);
    assert.doesNotMatch(alone, /Telemetria/);
    assert.doesNotMatch(alone, /section-label/);

    const accompanied = renderRequestCardGroup(group, [reading], true, []);
    assert.match(accompanied, /section-label[^>]*>Telemetria</);
    assert.match(accompanied, />1 com leitura</);
});

/**
 * Uma capacidade de que nunca chegou leitura sai da grelha: um mosaico do tamanho dos outros
 * dava-lhe o peso de quem tem valor para mostrar.
 */
test("uma capacidade que nunca mediu dá pastilha e não mosaico", () => {
    const [group] = telemetryRequestCards({ heart_rate: supported() });

    const html = renderRequestCardGroup(group, [], false, []);

    assert.match(html, /Sem leitura até agora/);
    // O catálogo de capacidades não está carregado aqui, e por isso o nome vem do
    // `humanizeCapabilityKey`.
    assert.match(html, /telemetry-pill/);
    assert.match(html, /Heart Rate/);
    assert.doesNotMatch(html, /telemetry-card-title/);
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
