import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { CONFIG_INPUTS as INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * As horas do dispensador escrevem-se num seletor de horas, e não em dois campos numéricos.
 *
 * Quatro caixas de número para dizer «das 22:00 às 07:00» obrigam quem configura a somar duas
 * parcelas de cabeça, e deixam escrever `25` e `99` até o aparelho recusar. O contrato para
 * fora não muda -- continua a sair hora e minuto separados, que é o que as TAGs levam --, e
 * por isso o que interessa provar é a ida e volta.
 */

const render = (input, desired) => parseFragment(INPUTS[input].render({ fields: [] }, desired));

test("as horas de silêncio desenham dois seletores de horas", () => {
    const root = render("pillDispenserQuietHours", {
        enabled: true,
        startHour: 22,
        startMinute: 30,
        endHour: 7,
        endMinute: 5,
    });

    const times = [...root.querySelectorAll("input[type=time]")];
    assert.equal(times.length, 2);
    assert.deepEqual(times.map((el) => el.getAttribute("value")), ["22:30", "07:05"]);
});

test("as horas de silêncio voltam separadas em hora e minuto", () => {
    const root = render("pillDispenserQuietHours", {
        enabled: true,
        startHour: 22,
        startMinute: 30,
        endHour: 7,
        endMinute: 5,
    });

    assert.deepEqual(INPUTS.pillDispenserQuietHours.read(root), {
        enabled: true,
        startHour: 22,
        startMinute: 30,
        endHour: 7,
        endMinute: 5,
    });
});

test("cada alarme tem um seletor de horas, e os nove aparecem sempre", () => {
    const root = render("pillDispenserAlarms", {
        plans: [{ hour: 8, minute: 30, enabled: true }],
    });

    const times = [...root.querySelectorAll("input[type=time]")];
    assert.equal(times.length, 9, "o aparelho tem nove alarmes fixos");
    assert.equal(times[0].getAttribute("value"), "08:30");
});

test("um alarme ligado volta com a hora que se escolheu", () => {
    const root = render("pillDispenserAlarms", {
        plans: [{ hour: 8, minute: 30, enabled: true }, { hour: 20, minute: 5, enabled: true }],
    });

    // O `slot` é o número do alarme e não a posição: sem ele, o enésimo plano caía no
    // enésimo alarme e escolher o 5 escrevia no 3.
    assert.deepEqual(INPUTS.pillDispenserAlarms.read(root), {
        plans: [
            { slot: 1, hour: 8, minute: 30, enabled: true },
            { slot: 2, hour: 20, minute: 5, enabled: true },
        ],
    });
});

/**
 * O aparelho devolve `24` e `60` nos slots que nunca foram definidos -- não são uma hora, são
 * o sentinela dele. Desenhá-los à letra dava um alarme às 24:60 no ecrã.
 */
test("as horas sentinela do aparelho não se desenham como hora", () => {
    const root = render("pillDispenserAlarms", {
        plans: [{ hour: 24, minute: 60, enabled: false }],
    });

    assert.equal(root.querySelectorAll("input[type=time]")[0].getAttribute("value"), "");
});
