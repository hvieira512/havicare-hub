import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/pill-dispenser.js";

/**
 * O número do alarme na dashboard tem de ser o alarme que chega ao aparelho: com os slots
 * vazios de fora, escolher o 5 escreveria no 3.
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

    assert.deepEqual(read.plans, [{ times: [{ time: "10:24", enabled: true, slot: 5, recurrence: { kind: "daily" } }] }]);
});

test("vários alarmes mantêm cada um o seu número", () => {
    const read = INPUTS.pillDispenserAlarms.read(
        section(nine({ 0: "11:00", 4: "10:24", 8: "20:00" })),
    );

    assert.deepEqual(
        read.plans.map((plan) => plan.times[0].slot),
        [1, 5, 9],
    );
});

/** Um slot em branco não viaja: o backend escreve-lhe o vazio do aparelho. */
test("os slots por preencher não entram no plano", () => {
    const read = INPUTS.pillDispenserAlarms.read(section(nine({})));

    assert.deepEqual(read.plans, []);
});

/** O desenho lê pelo número: um plano só do alarme 5 vai para a quinta caixa. */
test("o alarme 5 é desenhado na caixa 5", () => {
    const rendered = INPUTS.pillDispenserAlarms.render(
        {},
        { plans: [{ times: [{ time: "10:24", enabled: true, slot: 5, recurrence: { kind: "daily" } }] }] },
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
    const rendered = String(
        INPUTS.pillDispenserAlarms.render({}, { plans: [{ times: [{ time: "08:00", enabled: true, slot: 1, recurrence: { kind: "daily" } }] }] }),
    );

    assert.doesNotMatch(rendered, /type="checkbox"/);
});
