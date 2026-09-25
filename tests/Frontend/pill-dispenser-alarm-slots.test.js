import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/pill-dispenser.js";

/**
 * O número do alarme na dashboard tem de ser o alarme que chega ao aparelho.
 *
 * A leitura deixava cair os slots vazios e o plano seguia como lista compacta, com o backend
 * a colocar o enésimo plano no enésimo alarme. Escolher o 5 escrevia no 3, por cima do que lá
 * estivesse. Confirmou-se contra o aparelho: pediu-se o 5 e saíram os bytes do 3.
 */

const section = (times) => {
    const fields = new Map();
    for (const [index, time] of times.entries()) {
        fields.set(`time-${index}`, time ?? "");
    }

    return {
        querySelector: (selector) => {
            const field = selector.match(/\[data-config-field="([^"]+)"\]/)?.[1];
            if (field === undefined || !fields.has(field)) return null;

            return { value: fields.get(field), checked: false };
        },
    };
};

const nine = (overrides) => Array.from({ length: 9 }, (_, index) => overrides[index] ?? "");

test("o alarme escolhido leva o seu número, e não a posição na lista", () => {
    const read = INPUTS.pillDispenserAlarms.read(section(nine({ 4: "10:24" })));

    assert.deepEqual(read.plans, [{ slot: 5, hour: 10, minute: 24 }]);
});

test("vários alarmes mantêm cada um o seu número", () => {
    const read = INPUTS.pillDispenserAlarms.read(
        section(nine({ 0: "11:00", 4: "10:24", 8: "20:00" })),
    );

    assert.deepEqual(
        read.plans.map((plan) => plan.slot),
        [1, 5, 9],
    );
});

/** Um slot em branco não viaja: o backend escreve-lhe o vazio do aparelho. */
test("os slots por preencher não entram no plano", () => {
    const read = INPUTS.pillDispenserAlarms.read(section(nine({})));

    assert.deepEqual(read.plans, []);
});

/** E o desenho tem de ler pelo número, senão um plano só do alarme 5 aparecia na primeira caixa. */
test("o alarme 5 é desenhado na caixa 5", () => {
    const rendered = INPUTS.pillDispenserAlarms.render(
        {},
        { plans: [{ slot: 5, hour: 10, minute: 24 }] },
    );

    const cells = rendered.split("data-alarm-slot=\"").slice(1);
    assert.equal(cells.length, 9);
    assert.match(cells[4], /value="10:24"/);
    assert.doesNotMatch(cells[0], /value="10:24"/);
});

/**
 * Sem interruptor por alarme: esta firmware ignora o `0x1041`--`0x1049`, e um comando que não
 * comanda nada no cartão faz o utilizador julgar calado o que continua a tocar.
 */
test("o cartão não desenha interruptores", () => {
    const rendered = INPUTS.pillDispenserAlarms.render({}, { plans: [{ slot: 1, hour: 8, minute: 0 }] });

    assert.doesNotMatch(rendered, /type="checkbox"/);
});
