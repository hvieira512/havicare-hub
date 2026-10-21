import test from "node:test";
import assert from "node:assert/strict";

import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";

const battery = (data) => uplinkCardContent("battery", data);

/** Os relógios mandam um bit e o dispensador manda uma enumeração; a pergunta é a mesma. */
test("charging puts the bolt in the corner, in both shapes", () => {
    assert.notEqual(battery({ percent: 40, chargingState: 1 }).iconBadge, "");
    assert.notEqual(battery({ percent: 40, chargingState: "charging" }).iconBadge, "");
});

test("a battery that is not charging gets no bolt", () => {
    assert.equal(battery({ percent: 40, chargingState: 0 }).iconBadge, "");
    assert.equal(battery({ percent: 100, chargingState: "full" }).iconBadge, "");
    assert.equal(battery({ percent: 40 }).iconBadge, "");
});

/** O ícone diz o estado de carga; repeti-lo por baixo ocupa a linha com o mesmo. */
test("the charge state leaves the details line", () => {
    assert.equal(String(battery({ percent: 100, chargingState: "full" }).details), "");
    assert.equal(String(battery({ percent: 40, chargingState: "charging" }).details), "");
    assert.equal(String(battery({ percent: 40, chargingState: 1 }).details), "");
});

/** O que o ícone não sabe dizer fica: não há bateria rasurada no Font Awesome free. */
test("but «Sem bateria» stays, since no icon says it", () => {
    assert.match(String(battery({ chargingState: "absent" }).details), /Sem bateria/);
});

/** A bandeira é a mesma em todos os aparelhos, e diz-se igual em todos. */
test("low battery is said below and tints the tile, whatever the device", () => {
    for (const data of [
        { percent: 30, lowBattery: true },
        { percent: 14, batteryType: 3, lowBattery: true },
        { percent: 12, chargingState: "low", lowBattery: true },
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

/** A corrente mostra-se no canto do ícone. */
test("mains power is not written below", () => {
    for (const data of [
        { percent: 99, chargingState: "charging", mainsPowered: true },
        { percent: 100, chargingState: "full", mainsPowered: true },
        { percent: 80, mainsPowered: true },
        { percent: 92, chargingState: "full", mainsPowered: false },
    ]) {
        // Igualdade e não `doesNotMatch`: não encontrar «corrente» passaria com qualquer outro texto.
        assert.equal(String(battery(data).details), "");
    }
});

/** Cheio na ficha não carrega, e sem marca seria igual a um aparelho fora da ficha. */
test("on mains and not charging, the plug takes the corner", () => {
    assert.notEqual(battery({ percent: 100, chargingState: "full", mainsPowered: true }).iconBadge, "");
    assert.notEqual(battery({ percent: 80, mainsPowered: true }).iconBadge, "");
});

test("off mains the corner stays empty", () => {
    assert.equal(battery({ percent: 92, chargingState: "full", mainsPowered: false }).iconBadge, "");
    assert.equal(battery({ percent: 92, chargingState: "full" }).iconBadge, "");
});

/** Um fragmento vazio é um objecto, e um objecto é verdadeiro. */
test("without details the card opens no line", () => {
    const content = battery({ percent: 100, chargingState: "full" });
    const card = String(telemetryCard({
        icon: content.icon,
        title: "Bateria",
        value: "100%",
        details: content.details,
    }));

    assert.doesNotMatch(card, /telemetry-row-details/);
});
