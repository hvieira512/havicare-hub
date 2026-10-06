import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * O título diz o que a definição é e o botão o que o clique faz: repetir o rótulo no botão não
 * diz o que acontece quando o rótulo é um nome, como «Versão de firmware».
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

/** Uma definição guarda-se, e quem a envia é o rodapé da secção. O verbo é das acções. */
test("uma definição com campos não tem botão nenhum", () => {
    const section = parseFragment(renderConfigSection("wonlex-json", action({
        key: "step_goal",
        label: "Meta de passos",
        input: "number",
        fields: ["steps"],
        transient: false,
        verb: "Nunca usado",
    }), null));

    assert.equal(section.querySelectorAll("[data-action=\"saveConfig\"]").length, 0);
});
