import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
    defaultConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { configSection } from "./support/dom.js";

/**
 * O valor por omissão que o leitor devolve vai para o aparelho na primeira gravação de uma
 * secção intocada; o segundo teste obriga qualquer descritor novo a entrar na lista.
 */
const OPTIONS = { enabled: [{ value: 1, label: "Um" }, { value: 2, label: "Dois" }] };

/** O que cada tipo precisa para desenhar. Quem não está aqui basta-se com o mínimo. */
const FIXTURES = {
    select: { options: OPTIONS },
    volumeScale: { options: OPTIONS },
};

/** Os que não devolvem o que receberam: `reads` devolve outra coisa, `throws` estoira. */
const READ_DIFFERS = {
    call_whitelist: { kind: "reads", why: "o leitor tira as posições em branco dos dez lugares" },
    takePills: { kind: "reads", why: "o tipo do áudio só viaja quando há áudio" },
    wonlexMedicationPlans: { kind: "throws", why: "um plano sem nome não se envia" },
};

const entryFor = (input) => ({ input, key: input, fields: ["enabled"], ...FIXTURES[input] });

const inputsWithDefaults = () =>
    Object.entries(CONFIG_INPUTS)
        .filter(([, descriptor]) => typeof descriptor.defaults === "function")
        .map(([input]) => input);

test("o valor por omissão de cada tipo de campo lê-se de volta como ele é", () => {
    for (const input of inputsWithDefaults()) {
        if (input in READ_DIFFERS) continue;

        const entry = entryFor(input);
        const defaults = defaultConfigPayload(entry, "");
        const readBack = readConfigPayload(configSection(renderConfigInputs, entry, defaults, {}));

        assert.deepEqual(readBack, defaults, input);
    }
});

/** Uma divergência só vale enquanto for verdade: quando deixar de ser, sai da lista. */
test("os que divergem divergem mesmo, e os outros estão todos cobertos", () => {
    for (const [input, { kind, why }] of Object.entries(READ_DIFFERS)) {
        const entry = entryFor(input);
        const defaults = defaultConfigPayload(entry, "");
        const readBack = () =>
            readConfigPayload(configSection(renderConfigInputs, entry, defaults, {}));

        if (kind === "throws") {
            assert.throws(readBack, `${input} já não recusa: ${why}`);
            continue;
        }

        assert.notDeepEqual(readBack(), defaults, `${input} já não diverge: ${why}`);
    }

    const declared = new Set([...inputsWithDefaults(), ...Object.keys(READ_DIFFERS)]);
    assert.equal(
        declared.size,
        inputsWithDefaults().length,
        "a lista das divergências tem um tipo que já não existe",
    );
});
