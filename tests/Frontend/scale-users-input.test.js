import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs, readConfigPayload } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { handleScaleUserAction } from "../../src/Dashboard/dashboard/devices/config/inputs/scale.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { configSection } from "./support/dom.js";

/** Os utilizadores da balança, como a alternativa A: um cartão por pessoa, e o formulário só a editar. */
const entry = { input: "scaleUsers", key: "scale_users", fields: ["items"] };
const hugo = { id: "u-0a1b2c3d", name: "Hugo", ageYears: 23, sex: "male", heightCm: 181, athlete: false, weightKg: 96 };
const maria = { id: "u-00000002", name: "Maria", ageYears: 61, sex: "female", heightCm: 158, athlete: true, weightKg: 64.5 };

const render = (items, onlineUserId = undefined) => configSection(renderConfigInputs, entry, onlineUserId ? { items, onlineUserId } : { items });
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
    // O estado de cada pedido já diz se espera pela balança: não há faixa a repeti-lo.
    assert.doesNotMatch(section.textContent, /A dormir|Acordada/);
    assert.deepEqual(readConfigPayload(section), { items: [hugo, maria] });
});

test("o estado de cada pessoa sai da operação que lhe diz respeito, e só aparece enquanto não está aplicada", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { target: hugo.id, deliveryStatus: "acked", error: "" },
        { target: maria.id, deliveryStatus: "failed", error: "memory_full" },
    ] } } } };
    const section = render([hugo, maria]);

    assert.equal(card(section, "Hugo").querySelector(".badge"), null);
    assert.match(card(section, "Maria").textContent, /Falhou/);
    assert.match(card(section, "Maria").textContent, /memória cheia/);
});

test("quem está em linha fica marcado no rádio, sem emblema, quando a balança o aceitou", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { command: "selectScaleUser", target: hugo.id, deliveryStatus: "acked", error: "" },
    ] } } } };
    const section = render([hugo, maria], hugo.id);

    assert.equal(card(section, "Hugo").querySelector(".badge"), null);
    assert.equal(card(section, "Hugo").querySelector("input[type=\"radio\"]").checked, true);
});

test("pôr em linha mostra o estado enquanto a balança não o aceitou", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { command: "selectScaleUser", target: hugo.id, deliveryStatus: "waiting", error: "" },
    ] } } } };

    assert.match(card(render([hugo, maria], hugo.id), "Hugo").textContent, /A pôr em linha/);
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

test("o círculo é um rádio da lista: marcar não envia, e a escolha vai com a lista", () => {
    const section = render([hugo, maria], hugo.id);
    const radios = [...section.querySelectorAll("input[type=\"radio\"]")];

    assert.equal(radios.length, 2);
    assert.equal(new Set(radios.map((radio) => radio.name)).size, 1);
    assert.doesNotMatch(section.textContent, /Pôr em linha/);
    assert.equal(readConfigPayload(section).onlineUserId, hugo.id);

    card(section, "Maria").querySelector("input[type=\"radio\"]").checked = true;
    assert.equal(readConfigPayload(section).onlineUserId, maria.id);
});

test("uma pessoa por enviar ainda não pode ficar em linha", () => {
    const section = render([hugo]);

    click(section, "scaleUserAdd");
    section.querySelector("[data-scale-field=\"name\"]").value = "Ana";
    section.querySelector("[data-scale-field=\"ageYears\"]").value = "45";
    section.querySelector("[data-scale-field=\"heightCm\"]").value = "165";
    section.querySelector("[data-scale-field=\"weightKg\"]").value = "70";
    click(section, "scaleUserSave");

    assert.equal(card(section, "Ana").querySelector("input[type=\"radio\"]").disabled, true);
});

test("o histórico pede-se noutra entrada: os cartões só gerem a lista", () => {
    const section = render([hugo, maria], hugo.id);

    assert.doesNotMatch(section.textContent, /histórico/i);
});

test("ler o histórico escolhe o utilizador pelo nome, e sem ninguém registado não há o que enviar", () => {
    const history = { input: "scaleUserChoice", key: "read_scale_history", fields: ["id"] };
    state.deviceModal.configurations = { scale_users: { items: [hugo, maria] } };
    const section = configSection(renderConfigInputs, history, {});

    assert.deepEqual([...section.querySelectorAll("option")].map((option) => option.textContent), ["Hugo", "Maria"]);
    assert.deepEqual(readConfigPayload(section), { id: hugo.id });
    assert.equal(CONFIG_INPUTS.scaleUserChoice.ready(), true);

    state.deviceModal.configurations = {};
    assert.equal(CONFIG_INPUTS.scaleUserChoice.ready(), false);
});
