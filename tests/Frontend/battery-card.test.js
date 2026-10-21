import test from "node:test";
import assert from "node:assert/strict";

import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";

const battery = (data) => uplinkCardContent("battery", data);

test("charging puts the bolt in the corner", () => {
    assert.notEqual(battery({ percent: 40, charging: true }).iconBadge, "");
});

test("a battery that is not charging gets no bolt", () => {
    assert.equal(battery({ percent: 40, charging: false }).iconBadge, "");
    assert.equal(battery({ percent: 40 }).iconBadge, "");
});

/** O ícone diz se carrega; repeti-lo por baixo ocupa a linha com o mesmo. */
test("the charge state leaves the details line", () => {
    assert.equal(String(battery({ percent: 100, charging: false }).details), "");
    assert.equal(String(battery({ percent: 40, charging: true }).details), "");
});

/** A bandeira é a mesma em todos os aparelhos, e diz-se igual em todos. */
test("low battery is said below and tints the tile, whatever the device", () => {
    for (const data of [
        { percent: 30, lowBattery: true },
        { percent: 14, reportReason: "low_battery", lowBattery: true },
        { percent: 12, charging: false, lowBattery: true },
        { lowBattery: true },
    ]) {
        assert.equal(String(battery(data).details), "Bateria fraca", JSON.stringify(data));
        assert.notEqual(battery(data).tone, "success", JSON.stringify(data));
    }
});

test("with the flag off, nothing is said", () => {
    assert.equal(String(battery({ percent: 80, lowBattery: false }).details), "");
    assert.equal(battery({ percent: 80, lowBattery: false }).tone, "success");
});

/** O motivo do envio fica por baixo quando o aparelho o diz. */
test("the reason for sending shows below", () => {
    assert.match(String(battery({ percent: 80, reportReason: "periodic" }).details), /Envio periódico/);
});

/** A corrente mostra-se no canto do ícone. */
test("mains power is not written below", () => {
    for (const data of [
        { percent: 99, charging: true, mainsPowered: true },
        { percent: 100, charging: false, mainsPowered: true },
        { percent: 80, mainsPowered: true },
        { percent: 92, charging: false, mainsPowered: false },
    ]) {
        // Igualdade e não `doesNotMatch`: não encontrar «corrente» passaria com qualquer outro texto.
        assert.equal(String(battery(data).details), "");
    }
});

/** Cheio na ficha não carrega, e sem marca seria igual a um aparelho fora da ficha. */
test("on mains and not charging, the plug takes the corner", () => {
    assert.notEqual(battery({ percent: 100, charging: false, mainsPowered: true }).iconBadge, "");
    assert.notEqual(battery({ percent: 80, mainsPowered: true }).iconBadge, "");
});

test("off mains the corner stays empty", () => {
    assert.equal(battery({ percent: 92, charging: false, mainsPowered: false }).iconBadge, "");
    assert.equal(battery({ percent: 92, charging: false }).iconBadge, "");
});

/** Um fragmento vazio é um objecto, e um objecto é verdadeiro. */
test("without details the card opens no line", () => {
    const content = battery({ percent: 100, charging: false });
    const card = String(telemetryCard({
        icon: content.icon,
        title: "Bateria",
        value: "100%",
        details: content.details,
    }));

    assert.doesNotMatch(card, /telemetry-row-details/);
});
