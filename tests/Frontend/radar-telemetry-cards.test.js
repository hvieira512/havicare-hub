import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { telemetryCard, uplinkCardContent } from "../../src/Dashboard/dashboard/telemetry-cards.js";

/**
 * Os cartões do radar. A frequência cardíaca e a respiratória não aparecem aqui de propósito:
 * o radar manda `heart_rate {bpm}` e `breath_rate {breathsPerMinute}`, as mesmas chaves e
 * formas de um relógio, e por isso usa os cartões dele. Aqui está o que só o radar mede.
 */

/** A frequência respiratória mostra a leitura, e não um "há dados de". */
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
 * Cada pessoa leva a sua postura, e não há cartão de postura: uma divisão com duas pessoas
 * tem duas posturas, e um cartão do aparelho obrigava a escolher uma delas.
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

test("os alarmes dizem o que aconteceu, não só a categoria", () => {
    // "Queda" sozinho não distingue uma queda confirmada de alguém no chão.
    assert.equal(
        uplinkCardContent("fall", { detectionType: "fall_confirmed", detectionLevel: "perigo" }).value,
        "Queda confirmada",
    );
    assert.equal(
        uplinkCardContent("vitals_alarm", { detectionType: "apnea", detectionLevel: "perigo" }).details,
        "Perigo",
    );
    assert.equal(
        uplinkCardContent("presence_event", { detectionType: "room_exit", detectionLevel: "info" }).value,
        "Saiu da divisão",
    );
});

/**
 * O mosaico desenha o `details` que o `uplinkCardContent` devolve, e não só o `value`: sem
 * isto, o estado de sono era calculado a cada leitura e deitado fora antes de chegar ao ecrã.
 */
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
