import test from "node:test";
import assert from "node:assert/strict";
import { asText } from "./support/dom.js";

const { telemetryRequestCards, renderRequestCardGroup: buildGroup } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

const renderRequestCardGroup = asText(buildGroup);

/**
 * Os cartões de «Pedir dados» separam a telemetria, que o dispositivo mede, da informação do
 * sistema, que ele diz sobre si próprio.
 */

const supported = (requestable = true) => ({ supported: true, requestable });

test("a telemetry-only device gives one group, and a radar gives both", () => {
    const [telemetry] = telemetryRequestCards({
        heart_rate: supported(),
        battery: supported(),
    });
    assert.equal(telemetry.label, "Últimas leituras");

    const groups = telemetryRequestCards({
        heart_rate: supported(),
        firmware_version: supported(),
    });
    assert.deepEqual(
        groups.map((group) => group.label),
        ["Últimas leituras", "Informação do sistema"],
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
test("device status sits with the firmware version, not among the measurements", () => {
    const groups = telemetryRequestCards({
        battery: supported(false),
        device_status: supported(),
        firmware_version: supported(),
    });

    assert.deepEqual(
        groups.map((group) => [group.label, group.cards.map((card) => card.feature)]),
        [
            ["Últimas leituras", ["battery"]],
            ["Informação do sistema", ["device_status", "firmware_version"]],
        ],
    );
});

/** A proximidade já se mostra no resumo, uma linha por gateway em «Dispositivos ligados». */
test("proximity gets no tile, since the summary already shows it per gateway", () => {
    const groups = telemetryRequestCards({
        battery: supported(false),
        proximity: supported(false),
    });

    assert.deepEqual(
        groups.flatMap((group) => group.cards.map((card) => card.feature)),
        ["battery"],
    );
});

test("a capability the model lacks gets no card", () => {
    const groups = telemetryRequestCards({
        heart_rate: { supported: false, requestable: true },
        battery: supported(),
    });

    assert.deepEqual(
        groups.flatMap((group) => group.cards.map((card) => card.feature)),
        ["battery"],
    );
});

test("the group name strip exists only when there is more than one group", () => {
    const [group] = telemetryRequestCards({ heart_rate: supported() });

    // Num relógio, que só tem "Telemetria", a faixa seria uma moldura dentro de um cartão que
    // já se chama "Pedir dados".
    const alone = renderRequestCardGroup(group, [], false, []);
    assert.doesNotMatch(alone, /Últimas leituras/);

    const accompanied = renderRequestCardGroup(group, [], true, []);
    assert.match(accompanied, />Últimas leituras</);
});

test("with a reading, the tile shows the value", () => {
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
