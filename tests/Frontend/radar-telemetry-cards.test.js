import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";

/**
 * O radar manda `heart_rate` e `breath_rate` com as chaves e formas de um relógio, e usa os
 * cartões dele; aqui está o que só o radar mede.
 */

test("breath rate shows the reading, not fixed text", () => {
    assert.equal(uplinkCardContent("breath_rate", { breathsPerMinute: 17 }).value, "17 rpm");
    assert.equal(uplinkCardContent("breath_rate", {}).value, "- rpm");
});

test("the radar heart rate uses the watch card", () => {
    // Mesma chave, mesma forma `{bpm}`: o radar não tem cartão próprio, de propósito.
    assert.equal(uplinkCardContent("heart_rate", { bpm: 69 }).value, "69 bpm");
});

test("sleep state is a card with the value in Portuguese", () => {
    const card = uplinkCardContent("sleep_state", { state: "deep_sleep" });

    assert.equal(card.value, "Sono profundo");
});

test("a state the map does not know shows as it came instead of vanishing", () => {
    // O fabricante pode acrescentar estados numa versão nova do firmware. O hub manda a
    // enumeração à mesma, e mostrar "Rem Sleep" é melhor do que mostrar "-".
    assert.equal(uplinkCardContent("sleep_state", { state: "rem_sleep" }).value, "Rem Sleep");
});

test("a radar that sees nobody is working", () => {
    // "Ninguém" e não "Sem leituras": a diferença entre uma divisão vazia e um radar mudo.
    assert.equal(uplinkCardContent("presence", { peopleCount: 0, people: [] }).value, "Ninguém");
});

/**
 * Cada pessoa leva a sua postura, e não há cartão de postura: uma divisão com duas pessoas tem
 * duas posturas.
 */
const person = (index, posture) => ({
    personIndex: index,
    posture,
    xPositionDm: index + 3,
    yPositionDm: index,
    zPositionCm: 0,
});

test("presence shows each person's posture, not one for the device", () => {
    const card = uplinkCardContent("presence", {
        peopleCount: 2,
        people: [person(0, "standing"), person(1, "fallen")],
    });

    assert.equal(card.value, "2 pessoas");

    const chips = parseFragment(`<div>${card.details}</div>`).querySelectorAll(".badge");
    assert.equal(chips.length, 2);
    assert.equal(chips[0].textContent, "De pé");
    assert.equal(chips[1].textContent, "Queda confirmada");
});

/**
 * O corte às três primeiras tem contador, para a quarta pessoa não desaparecer sem aviso. As
 * coordenadas e quem não coube ficam na tooltip, que não corta.
 */
test("the fourth person becomes a counter instead of vanishing", () => {
    const card = uplinkCardContent("presence", {
        peopleCount: 5,
        people: [
            person(0, "lying_down"),
            person(1, "standing"),
            person(2, "fallen"),
            person(3, "walking"),
            person(4, "walking"),
        ],
    });

    const chips = [...parseFragment(`<div>${card.details}</div>`).querySelectorAll(".badge")];
    assert.equal(chips.length, 4);
    assert.equal(chips[3].textContent, "+2");

    assert.match(card.detailsTitle, /Pessoa 4: A andar/);
    assert.match(card.detailsTitle, /Pessoa 5: A andar/);
    assert.match(card.detailsTitle, /Pessoa 1: Deitado · x 3 dm · y 0 dm · z 0 cm/);
});

test("a posture the firmware makes up writes no attributes", () => {
    const card = uplinkCardContent("presence", {
        peopleCount: 1,
        people: [{ personIndex: 0, posture: "\"><script>alert(1)</script>" }],
    });

    assert.equal(card.details.includes("<script>"), false);
    const chip = parseFragment(`<div>${card.details}</div>`).querySelector(".badge");
    assert.equal(chip.querySelector("[onerror], script"), null);
});

test("a fall says whether it was confirmed and how the person ended up", () => {
    // "Queda" sozinho não distingue uma queda confirmada de uma suspeita, nem de alguém sentado no chão.
    const fall = (data) => uplinkCardContent("fall", data).value;

    assert.equal(fall({ confirmed: true, posture: "lying_down", personIndex: 0 }), "Queda confirmada");
    assert.equal(fall({ confirmed: false, posture: "lying_down", personIndex: 0 }), "Queda suspeita");
    assert.equal(fall({ confirmed: true, posture: "sitting_on_ground", personIndex: 0 }), "Sentado no chão");
    // O relógio não diz a postura: só que detetou a queda.
    assert.equal(fall({ confirmed: true }), "Queda detetada");
    assert.equal(uplinkCardContent("fall", { confirmed: true, posture: "lying_down", personIndex: 1 }).details, "Pessoa 2");
});

test("an entry or exit says the zone, and the area by its given name", () => {
    const value = (type, data) => uplinkCardContent(type, data).value;

    assert.equal(value("zone_exit", { zone: "room", personIndex: 0 }), "Saiu da divisão");
    assert.equal(value("zone_entry", { zone: "area", personIndex: 0, areaId: 2, areaName: "Porta", areaType: "door" }), "Entrou na área «Porta»");
    // Sem planta sincronizada não há nome, e não se inventa um.
    assert.equal(value("zone_entry", { zone: "area", personIndex: 0, areaId: 5 }), "Entrou na área");
});

test("abnormal vitals show the value that raised them", () => {
    assert.equal(uplinkCardContent("heart_rate_high", { bpm: 172 }).value, "172 bpm");
    assert.equal(uplinkCardContent("heart_rate_low", { bpm: 32 }).value, "32 bpm");
    assert.equal(uplinkCardContent("breath_rate_low", { breathsPerMinute: 6 }).value, "6 rpm");
});

/** Os quatro estados do `hbstatics` chegam em enumeração, como o `posture` e o `sleep_state`. */
test("per-minute states show in Portuguese from the enum", () => {
    const details = uplinkCardContent("vitals_minute_stats", {
        realTimeHeartRateBpm: 70,
        realTimeBreathsPerMinute: 13,
        breathingStatus: "hypopnea",
        heartRateStatus: "normal",
        vitalSignsStatus: "weak",
    }).details;

    assert.match(details, /Hipopneia/);
    assert.match(details, /Normal/);
    assert.match(details, /Fraco/);
});

test("the minute stats read the averages and the people count", () => {
    assert.equal(uplinkCardContent("vitals_minute_stats", { avgHeartRateBpm: 64, avgBreathsPerMinute: 15 }).value, "64 bpm · 15 rpm");
    assert.equal(uplinkCardContent("position_minute_stats", { peopleCount: 2, walkingDistanceMeters: 4 }).value, "2 pessoas · 4 m");
});

/** O mosaico desenha o `details` que o `uplinkCardContent` devolve, e não só o `value`. */
test("the tile shows the detail instead of dropping it", () => {
    const root = parseFragment(
        telemetryCard({ icon: "fa-bed", title: "Presença", value: "2 pessoas", details: "Pessoa 1 · x 3 dm" }),
    );

    assert.equal(root.querySelector(".telemetry-row-details").textContent, "Pessoa 1 · x 3 dm");
});

test("a cellular radar shows the network and signal quality", () => {
    const card = uplinkCardContent("connectivity", { interface: "cellular", signalQuality: 21 });

    assert.match(card.value, /^Rede móvel/);
    assert.match(card.details, /Qualidade do sinal: 21/);
});

test("tilt shows both axes in degrees and whether it was factory calibrated", () => {
    const card = uplinkCardContent("tilt", { xDegrees: 1.2, yDegrees: 0.9, zDegrees: 88, factoryCalibrated: true });

    assert.equal(card.value, "X 1,2° · Y 0,9°");
    assert.equal(card.details, "Calibrado de fábrica");
});

test("the radar firmware also shows the communication firmware", () => {
    const card = uplinkCardContent("firmware_version", { version: "V1.3.2", communicationVersion: "C2.0.1" });

    assert.equal(card.value, "V1.3.2");
    assert.match(card.details, /Firmware de comunicação: C2\.0\.1/);
});
