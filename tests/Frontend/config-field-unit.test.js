import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { numberField } =
    await import("../../src/Dashboard/dashboard/devices/config/inputs/shared.js");

/**
 * A unidade cola-se ao campo em vez de andar solta no nome da definição. «Intervalo
 * (minutos)» com uma caixa a dizer `60` obriga a ler duas coisas em sítios diferentes para
 * saber uma; `60` seguido de `min` é uma medida só.
 */

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
