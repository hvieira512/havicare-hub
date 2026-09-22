import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * O título diz o que a definição é; o botão diz o que o clique faz. Repetir a mesma frase nos
 * dois é escrevê-la duas vezes na mesma linha, e das vinte e uma acções do catálogo vinte têm
 * o rótulo como frase do verbo. Nas que são nomes -- «Versão de firmware», «Estado do
 * dispositivo» -- o botão deixava mesmo de dizer o que acontece ao carregar.
 */
const action = (over = {}) => ({
    key: "restart_device",
    capabilityKey: "restart_device",
    command: "config:restart_device",
    label: "Reiniciar dispositivo",
    input: "action",
    fields: [],
    transient: true,
    ...over,
});

const buttonLabel = (entry) => parseFragment(renderConfigSection("wonlex-json", entry, null))
    .querySelector("[data-action=\"saveConfig\"]")
    .textContent
    .trim();

test("sem verbo declarado, o botão não repete o título", () => {
    const entry = action();

    assert.notEqual(buttonLabel(entry), entry.label);
    assert.equal(buttonLabel(entry), "Enviar");
});

/** Onde o rótulo é um nome, a definição declara o verbo e é ele que manda. */
test("o verbo declarado ganha ao valor por omissão", () => {
    assert.equal(
        buttonLabel(action({
            key: "reset_device",
            label: "Reposição de fábrica",
            verb: "Repor de fábrica",
        })),
        "Repor de fábrica",
    );
});

/** O cartão de uma definição continua a guardar-se, e o botão dela não é um verbo. */
test("uma definição com campos continua a dizer Enviar", () => {
    assert.equal(
        buttonLabel(action({
            key: "step_goal",
            label: "Meta de passos",
            input: "number",
            fields: ["steps"],
            transient: false,
            verb: "Nunca usado",
        })),
        "Enviar",
    );
});
