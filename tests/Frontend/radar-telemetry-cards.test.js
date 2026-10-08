import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";

/**
 * O radar manda `heart_rate` e `breath_rate` com as chaves e formas de um relógio, e usa os
 * cartões dele; aqui está o que só o radar mede.
 */

test("a frequência respiratória mostra a leitura e não um texto fixo", () => {
    assert.equal(uplinkCardContent("breath_rate", { breathsPerMinute: 17 }).value, "17 rpm");
    assert.equal(uplinkCardContent("breath_rate", {}).value, "- rpm");
});

test("a frequência cardíaca do radar usa o cartão do relógio", () => {
    // Mesma chave, mesma forma `{bpm}`: o radar não tem cartão próprio, de propósito.
    assert.equal(uplinkCardContent("heart_rate", { bpm: 69 }).value, "69 bpm");
});

test("o estado de sono e um cartão com o valor em português", () => {
    const card = uplinkCardContent("sleep_state", { state: "deep_sleep" });

    assert.equal(card.value, "Sono profundo");
    assert.equal(card.icon, "fa-bed");
});

test("um estado que o mapa não conhece mostra-se como veio, não desaparece", () => {
    // O fabricante pode acrescentar estados numa versão nova do firmware. O hub manda a
    // enumeração à mesma, e mostrar "Rem Sleep" é melhor do que mostrar "-".
    assert.equal(uplinkCardContent("sleep_state", { state: "rem_sleep" }).value, "Rem Sleep");
});

test("um radar que não vê ninguém está a funcionar", () => {
    // "Ninguém" e não "Sem leituras": a diferença entre uma divisão vazia e um radar mudo.
    assert.equal(uplinkCardContent("presence", { count: 0, people: [] }).value, "Ninguém");
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

test("a presença mostra a postura de cada pessoa, não uma só do aparelho", () => {
    const card = uplinkCardContent("presence", {
        count: 2,
        people: [person(0, "standing"), person(1, "fall_confirmation")],
    });

    assert.equal(card.value, "2 pessoas");

    const chips = parseFragment(`<div>${card.details}</div>`).querySelectorAll(".badge");
    assert.equal(chips.length, 2);
    assert.equal(chips[0].textContent, "De pé");
    assert.equal(chips[1].textContent, "Queda confirmada");
});

test("o tom da pastilha diz a gravidade, e o ícone a categoria", () => {
    const card = uplinkCardContent("presence", {
        count: 3,
        people: [person(0, "lying_down"), person(1, "suspected_fall"), person(2, "fall_confirmation")],
    });

    const chips = [...parseFragment(`<div>${card.details}</div>`).querySelectorAll(".badge")];

    // Repouso, suspeita, confirmação — a mesma escala do resto da dashboard.
    assert.deepEqual(
        chips.map((c) => [...c.classList].find((n) => n.startsWith("bg-"))),
        ["bg-info-subtle", "bg-warning-subtle", "bg-danger-subtle"],
    );

    // A suspeita e a confirmação partilham o triângulo e separam-se pela cor.
    assert.deepEqual(
        chips.map((c) => c.querySelector("i").className),
        [
            "fa-solid fa-bed",
            "fa-solid fa-triangle-exclamation",
            "fa-solid fa-triangle-exclamation",
        ],
    );
});

/**
 * O corte às três primeiras tem contador, para a quarta pessoa não desaparecer sem aviso. As
 * coordenadas e quem não coube ficam na tooltip, que não corta.
 */
test("a quarta pessoa vira contador em vez de desaparecer", () => {
    const card = uplinkCardContent("presence", {
        count: 5,
        people: [
            person(0, "lying_down"),
            person(1, "standing"),
            person(2, "fall_confirmation"),
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

test("uma postura que o firmware invente não escreve atributos", () => {
    const card = uplinkCardContent("presence", {
        count: 1,
        people: [{ personIndex: 0, posture: "\"><script>alert(1)</script>" }],
    });

    assert.equal(card.details.includes("<script>"), false);
    const chip = parseFragment(`<div>${card.details}</div>`).querySelector(".badge");
    assert.equal(chip.querySelector("i").className, "fa-solid fa-question");
});

test("uma queda diz se foi confirmada e como a pessoa ficou", () => {
    // "Queda" sozinho não distingue uma queda confirmada de uma suspeita, nem de alguém sentado no chão.
    const fall = (data) => uplinkCardContent("fall", data).value;

    assert.equal(fall({ confirmed: true, posture: "lying", personIndex: 0 }), "Queda confirmada");
    assert.equal(fall({ confirmed: false, posture: "lying", personIndex: 0 }), "Queda suspeita");
    assert.equal(fall({ confirmed: true, posture: "sitting_on_ground", personIndex: 0 }), "Sentado no chão");
    // O relógio não diz a postura: só que detetou a queda.
    assert.equal(fall({ confirmed: true }), "Queda detetada");
    assert.equal(uplinkCardContent("fall", { confirmed: true, posture: "lying", personIndex: 1 }).details, "Pessoa 2");
});

test("uma entrada ou saída diz a zona, e a área pelo nome que lhe deram", () => {
    const value = (type, data) => uplinkCardContent(type, data).value;

    assert.equal(value("zone_exit", { zone: "room", personIndex: 0 }), "Saiu da divisão");
    assert.equal(value("zone_entry", { zone: "area", personIndex: 0, areaId: 2, areaName: "Porta", areaType: "door" }), "Entrou na área «Porta»");
    // Sem planta sincronizada não há nome, e não se inventa um.
    assert.equal(value("zone_entry", { zone: "area", personIndex: 0, areaId: 5 }), "Entrou na área");
    assert.equal(value("zone_exit", { zone: "geofence" }), "Saiu da zona segura");
});

test("os vitais fora do normal mostram o valor que os levantou", () => {
    assert.equal(uplinkCardContent("heart_rate_high", { bpm: 172 }).value, "172 bpm");
    assert.equal(uplinkCardContent("heart_rate_low", { bpm: 32 }).value, "32 bpm");
    assert.equal(uplinkCardContent("breath_rate_low", { breathsPerMinute: 6 }).value, "6 rpm");
});

/** Os quatro estados do `hbstatics` chegam em enumeração, como o `posture` e o `sleep_state`. */
test("os estados por minuto saem em português a partir da enumeração", () => {
    const details = uplinkCardContent("vitals_minute_stats", {
        realTimeHeartRate: 70,
        realTimeBreathing: 13,
        breathingStatus: "hypopnea",
        heartRateStatus: "normal",
        vitalSignsStatus: "weak",
    }).details;

    assert.match(details, /Hipopneia/);
    assert.match(details, /Normal/);
    assert.match(details, /Fraco/);
});

/** O mosaico desenha o `details` que o `uplinkCardContent` devolve, e não só o `value`. */
test("o mosaico mostra o detalhe em vez de o deitar fora", () => {
    const root = parseFragment(
        telemetryCard({ icon: "fa-bed", title: "Presença", value: "2 pessoas", details: "Pessoa 1 · x 3 dm" }),
    );

    assert.equal(root.querySelector(".telemetry-row-details").textContent, "Pessoa 1 · x 3 dm");
});

test("um mosaico sem detalhe não desenha a linha vazia", () => {
    const root = parseFragment(telemetryCard({ icon: "fa-bed", title: "Sono", value: "Acordado" }));

    assert.equal(root.querySelector(".telemetry-row-details"), null);
});
