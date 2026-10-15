import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs, readConfigPayload } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { handleScaleUserAction, showOnlineUser } from "../../src/Dashboard/dashboard/devices/config/inputs/scale.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { configSection } from "./support/dom.js";

/** Os utilizadores da balança, como a alternativa A: um cartão por pessoa, e o formulário só a editar. */
const entry = { input: "scaleUsers", key: "scale_users", fields: ["items"] };
const hugo = { id: "u-0a1b2c3d", name: "Hugo", ageYears: 23, sex: "male", heightCm: 181, athlete: false, weightKg: 96 };
const maria = { id: "u-00000002", name: "Maria", ageYears: 61, sex: "female", heightCm: 158, athlete: true, weightKg: 64.5 };

const render = (items) => configSection(renderConfigInputs, entry, { items });
const click = (section, action, scope = section) => {
    const button = scope.querySelector(`[data-action="${action}"]`);
    assert.ok(button, `sem botão ${action}`);
    handleScaleUserAction(section, button);
};
const card = (section, name) => [...section.querySelectorAll("[data-scale-user]")]
    .find((element) => element.textContent.includes(name));

beforeEach(() => {
    state.deviceModal.imei = "cfe908067329";
    state.deviceModal.online = false;
    state.deviceModal.configurationSync = { entries: {} };
    state.selectedDetail = null;
});

test("cada pessoa é um cartão com o perfil, sem formulário aberto nem identificador à vista", () => {
    const section = render([hugo, maria]);

    assert.match(card(section, "Hugo").textContent, /23 anos · homem · 181 cm · 96 kg/);
    assert.match(card(section, "Maria").textContent, /atleta/);
    assert.equal(section.querySelector("[data-scale-user-form]").hidden, true);
    assert.doesNotMatch(section.textContent, /u-0a1b2c3d/);
    assert.deepEqual(readConfigPayload(section), { items: [hugo, maria] });
});

test("o estado de cada pessoa sai da operação que lhe diz respeito", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { target: hugo.id, deliveryStatus: "acked", error: "" },
        { target: maria.id, deliveryStatus: "failed", error: "memory_full" },
    ] } } } };
    const section = render([hugo, maria]);

    assert.match(card(section, "Hugo").textContent, /Na balança/);
    assert.match(card(section, "Maria").textContent, /Falhou/);
    assert.match(card(section, "Maria").textContent, /memória cheia/);
});

test("quem a balança aceitou em linha por último fica destacado", () => {
    state.selectedDetail = { device: { imei: "cfe908067329" }, recent: { commands: [
        { nativeType: "selectScaleUser", status: "acked", bytes: `selectScaleUser:${maria.id}`, requestedAt: "2026-10-15T10:00:00Z" },
        { nativeType: "selectScaleUser", status: "acked", bytes: `selectScaleUser:${hugo.id}`, requestedAt: "2026-10-15T11:00:00Z" },
    ] } };
    const section = render([hugo, maria]);

    assert.match(card(section, "Hugo").textContent, /Em linha/);
    assert.doesNotMatch(card(section, "Maria").textContent, /Em linha/);
});

test("acrescentar abre o formulário, e guardar junta um cartão por enviar e sem identificador", () => {
    const section = render([hugo]);

    click(section, "scaleUserAdd");
    const form = section.querySelector("[data-scale-user-form]");
    assert.equal(form.hidden, false);
    form.querySelector("[data-scale-field=\"name\"]").value = "Ana";
    form.querySelector("[data-scale-field=\"ageYears\"]").value = "45";
    form.querySelector("[data-scale-field=\"heightCm\"]").value = "165";
    form.querySelector("[data-scale-field=\"weightKg\"]").value = "70";
    handleScaleUserAction(section, form.querySelector("[data-action=\"scaleUserSex\"][data-value=\"female\"]"));
    click(section, "scaleUserSave");

    assert.equal(form.hidden, true);
    assert.match(card(section, "Ana").textContent, /Por enviar/);
    assert.deepEqual(readConfigPayload(section).items[1], {
        name: "Ana", ageYears: 45, sex: "female", heightCm: 165, athlete: false, weightKg: 70,
    });
});

test("editar mantém o identificador, e apagar tira a pessoa da lista", () => {
    const section = render([hugo, maria]);

    click(section, "scaleUserEdit", card(section, "Hugo"));
    section.querySelector("[data-scale-field=\"weightKg\"]").value = "95";
    click(section, "scaleUserSave");
    assert.equal(readConfigPayload(section).items[0].weightKg, 95);
    assert.equal(readConfigPayload(section).items[0].id, hugo.id);

    click(section, "scaleUserEdit", card(section, "Maria"));
    click(section, "scaleUserDelete");
    assert.deepEqual(readConfigPayload(section).items.map((user) => user.name), ["Hugo"]);
});

test("sem nome não se guarda", () => {
    const section = render([]);

    click(section, "scaleUserAdd");
    click(section, "scaleUserSave");

    assert.equal(section.querySelector("[data-scale-user-form]").hidden, false);
    assert.deepEqual(readConfigPayload(section), { items: [] });
});

test("o círculo de cada pessoa é o que a põe em linha, e não há botão à parte", () => {
    const section = render([hugo, maria]);

    assert.equal(section.querySelectorAll("[data-action=\"scaleUserSelect\"][role=\"radio\"]").length, 2);
    assert.doesNotMatch(section.textContent, /Pôr em linha/);
});

test("quando a balança aceita, a pessoa passa a estar em linha e a anterior deixa de estar", () => {
    state.selectedDetail = { device: { imei: "cfe908067329" }, recent: { commands: [
        { nativeType: "selectScaleUser", status: "acked", bytes: `selectScaleUser:${hugo.id}`, requestedAt: "2026-10-15T11:00:00Z" },
    ] } };
    const section = render([hugo, maria]);

    showOnlineUser(section, maria.id);

    assert.match(card(section, "Maria").textContent, /Em linha/);
    assert.doesNotMatch(card(section, "Hugo").textContent, /Em linha/);
    assert.equal(card(section, "Maria").querySelector("[role=\"radio\"]").getAttribute("aria-checked"), "true");
});
