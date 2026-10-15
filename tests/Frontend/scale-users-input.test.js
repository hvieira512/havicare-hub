import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs, readConfigPayload } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { configSection } from "./support/dom.js";

/** Os utilizadores da balança: o que o formulário desenha é o que o leitor devolve, com o id do hub. */
const users = {
    input: "scaleUsers",
    key: "scale_users",
    fields: ["items"],
};
const hugo = { id: "u-0a1b2c3d", name: "Hugo", ageYears: 23, sex: "male", heightCm: 181, athlete: false, weightKg: 96 };
const maria = { id: "u-00000002", name: "Maria", ageYears: 61, sex: "female", heightCm: 158, athlete: true, weightKg: 64.5 };

test("a lista volta igual, com o identificador escondido de cada um", () => {
    const section = configSection(renderConfigInputs, users, { items: [hugo, maria] });

    assert.deepEqual(readConfigPayload(section), { items: [hugo, maria] });
    assert.doesNotMatch(section.textContent, /u-0a1b2c3d/);
});

test("uma linha nova vai sem identificador, e uma vazia não vai", () => {
    const section = configSection(renderConfigInputs, users, { items: [] });

    assert.deepEqual(readConfigPayload(section), { items: [] });
});

test("escolher o utilizador mostra os registados pelo nome, e o id é o valor", () => {
    state.deviceModal.configurations = { scale_users: { items: [hugo, maria] } };
    const choice = { input: "scaleUserChoice", key: "select_scale_user", fields: ["id"] };
    const section = configSection(renderConfigInputs, choice, {});
    const options = [...section.querySelectorAll("option")].map((option) => [option.value, option.textContent]);

    assert.deepEqual(options, [["u-0a1b2c3d", "Hugo"], ["u-00000002", "Maria"]]);
    assert.deepEqual(readConfigPayload(section), { id: "u-0a1b2c3d" });
});
