import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { numberField } =
    await import("../../src/Dashboard/dashboard/devices/config/inputs/shared.js");
const { renderConfigSection } =
    await import("../../src/Dashboard/dashboard/devices/config/index.js");

/** A unidade cola-se ao campo: `60` seguido de `min` é uma medida só. */

test("sem unidade o campo fica como está, sem embrulho nenhum", () => {
    const root = parseFragment(numberField("intervalMinutes", 60));

    assert.equal(root.querySelector(".input-group"), null);
    assert.equal(root.querySelector("input").value, "60");
});

test("com unidade o campo entra num grupo, com a unidade ao lado", () => {
    const root = parseFragment(numberField("intervalMinutes", 60, { unit: "min" }));

    const group = root.querySelector(".input-group");
    assert.ok(group, "o campo tem de ficar dentro de um `input-group`");
    assert.equal(group.querySelector("input").dataset.configField, "intervalMinutes");
    assert.equal(group.querySelector(".input-group-text").textContent, "min");
});

test("a unidade é escapada: vem do catálogo e não do código", () => {
    const root = parseFragment(numberField("x", 1, { unit: "<b>h</b>" }));

    assert.equal(root.querySelector(".input-group-text").textContent, "<b>h</b>");
});

const unitOf = (protocol, entry) => parseFragment(renderConfigSection(protocol, entry, null))
    .querySelector(".input-group-text")?.textContent.trim();

/** A unidade declarada em `options.label` ganha à que se adivinha pelo nome do campo. */
test("a unidade declarada aparece ao lado do campo", () => {
    assert.equal(unitOf("zayata-m228", {
        key: "retrieval_warning",
        command: "retrievalWarning",
        label: "Avisar de atraso ao fim de",
        input: "number",
        fields: ["minutes"],
        options: { min: 0, max: 1440, label: "Minutos" },
    }), "Minutos");
});

/** O protocolo da Wonlex diz que o `intervalTime` é em segundos. */
test("sem unidade declarada vale a do nome nativo", () => {
    assert.equal(unitOf("wonlex-json", {
        key: "locationInterval",
        command: "locationInterval",
        label: "Intervalo de localização",
        input: "number",
        fields: ["intervalTime"],
    }), "s");
});

/** Na balança, `unit` é o nome do campo e o que lá está são as escolhas do seletor. */
test("um campo chamado unit não passa as escolhas por unidade", () => {
    assert.equal(unitOf("lefu-torre", {
        key: "weight_unit",
        command: "weightUnit",
        label: "Unidade do visor",
        input: "select",
        fields: ["unit"],
        options: { unit: [{ value: "kg", label: "Quilogramas" }, { value: "lb", label: "Libras" }], default: "kg" },
    }), undefined);
});
