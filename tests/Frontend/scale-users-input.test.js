import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { renderConfigInputs, readConfigPayload } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { handleScaleUserAction } from "../../src/Dashboard/dashboard/devices/config/inputs/scale.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { configSection } from "./support/dom.js";

/** Os utilizadores da balança: um cartão por pessoa, e o formulário só a editar. */
const entry = { input: "scaleUsers", key: "scale_users", fields: ["items"] };
const yearsAgo = (years) => `${new Date().getFullYear() - years}-01-01`;
const hugo = { id: "u-0a1b2c3d", name: "Hugo", birthDate: yearsAgo(23), sex: "male", heightCm: 181, athlete: false, weightKg: 96 };
const maria = { id: "u-00000002", name: "Maria", birthDate: yearsAgo(61), sex: "female", heightCm: 158, athlete: true, weightKg: 64.5 };

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

test("each person is a card with the profile, no open form and no visible identifier", () => {
    const section = render([hugo, maria]);

    assert.match(card(section, "Hugo").textContent, /23 anos · homem · 181 cm · 96 kg/);
    assert.match(card(section, "Maria").textContent, /atleta/);
    assert.equal(section.querySelector("[data-scale-user-form]").hidden, true);
    assert.doesNotMatch(section.textContent, /u-0a1b2c3d/);
    // O estado de cada pedido já diz se espera pela balança: não há faixa a repeti-lo.
    assert.doesNotMatch(section.textContent, /A dormir|Acordada/);
    assert.deepEqual(readConfigPayload(section), { items: [hugo, maria] });
});

test("each person's state comes from their own operation, and shows only until applied", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { target: hugo.id, deliveryStatus: "acked", error: "" },
        { target: maria.id, deliveryStatus: "failed", error: "memory_full" },
    ] } } } };
    const section = render([hugo, maria]);

    assert.equal(card(section, "Hugo").querySelector(".badge"), null);
    assert.match(card(section, "Maria").textContent, /Falhou/);
    assert.match(card(section, "Maria").textContent, /memória cheia/);
});

test("the online person is marked in the radio, without a badge, once the scale accepted it", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { command: "selectScaleUser", target: hugo.id, deliveryStatus: "acked", error: "" },
    ] } } } };
    const section = render([hugo, maria], hugo.id);

    assert.equal(card(section, "Hugo").querySelector(".badge"), null);
    assert.equal(card(section, "Hugo").querySelector("input[type=\"radio\"]").checked, true);
});

test("setting online shows the state until the scale accepts it", () => {
    state.deviceModal.configurationSync = { entries: { health: { scale_users: { operations: [
        { command: "selectScaleUser", target: hugo.id, deliveryStatus: "waiting", error: "" },
    ] } } } };

    assert.match(card(render([hugo, maria], hugo.id), "Hugo").textContent, /A pôr em linha/);
});

test("adding opens the form, and saving appends an unsent card without identifier", () => {
    const section = render([hugo]);

    click(section, "scaleUserAdd");
    const form = section.querySelector("[data-scale-user-form]");
    assert.equal(form.hidden, false);
    form.querySelector("[data-scale-field=\"name\"]").value = "Ana";
    form.querySelector("[data-scale-field=\"birthDate\"]").value = "1981-04-02";
    form.querySelector("[data-scale-field=\"heightCm\"]").value = "165";
    form.querySelector("[data-scale-field=\"weightKg\"]").value = "70";
    handleScaleUserAction(section, form.querySelector("[data-action=\"scaleUserSex\"][data-value=\"female\"]"));
    click(section, "scaleUserSave");

    assert.equal(form.hidden, true);
    assert.match(card(section, "Ana").textContent, /Por enviar/);
    assert.deepEqual(readConfigPayload(section).items[1], {
        name: "Ana", birthDate: "1981-04-02", sex: "female", heightCm: 165, athlete: false, weightKg: 70,
    });
});

test("editing keeps the identifier, and deleting removes the person from the list", () => {
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

test("someone saved with only an age shows it, and editing asks for the birth date", () => {
    const section = render([{ id: "u-00000009", name: "Rui", ageYears: 40, sex: "male", heightCm: 175, athlete: false, weightKg: 80 }]);

    assert.match(card(section, "Rui").textContent, /40 anos/);
    click(section, "scaleUserEdit", card(section, "Rui"));
    click(section, "scaleUserSave");
    assert.match(section.querySelector("[data-scale-form-error]").textContent, /data de nascimento/);
});

test("nothing is saved without a name", () => {
    const section = render([]);

    click(section, "scaleUserAdd");
    click(section, "scaleUserSave");

    assert.equal(section.querySelector("[data-scale-user-form]").hidden, false);
    assert.deepEqual(readConfigPayload(section), { items: [] });
});

test("the circle is a list radio: checking does not send, and the choice goes with the list", () => {
    const section = render([hugo, maria], hugo.id);
    const radios = [...section.querySelectorAll("input[type=\"radio\"]")];

    assert.equal(radios.length, 2);
    assert.equal(new Set(radios.map((radio) => radio.name)).size, 1);
    assert.doesNotMatch(section.textContent, /Pôr em linha/);
    assert.equal(readConfigPayload(section).onlineUserId, hugo.id);

    card(section, "Maria").querySelector("input[type=\"radio\"]").checked = true;
    assert.equal(readConfigPayload(section).onlineUserId, maria.id);
});

test("an unsent person cannot be set online yet", () => {
    const section = render([hugo]);

    click(section, "scaleUserAdd");
    section.querySelector("[data-scale-field=\"name\"]").value = "Ana";
    section.querySelector("[data-scale-field=\"birthDate\"]").value = "1981-04-02";
    section.querySelector("[data-scale-field=\"heightCm\"]").value = "165";
    section.querySelector("[data-scale-field=\"weightKg\"]").value = "70";
    click(section, "scaleUserSave");

    assert.equal(card(section, "Ana").querySelector("input[type=\"radio\"]").disabled, true);
});

test("history is requested in another entry: the cards only manage the list", () => {
    const section = render([hugo, maria], hugo.id);

    assert.doesNotMatch(section.textContent, /histórico/i);
});

test("reading history picks the user by name, and with nobody registered there is nothing to send", () => {
    const history = { input: "scaleUserChoice", key: "read_scale_history", fields: ["id"] };
    state.deviceModal.configurations = { scale_users: { items: [hugo, maria] } };
    const section = configSection(renderConfigInputs, history, {});

    assert.deepEqual([...section.querySelectorAll("option")].map((option) => option.textContent), ["Hugo", "Maria"]);
    assert.deepEqual(readConfigPayload(section), { id: hugo.id });
    assert.equal(CONFIG_INPUTS.scaleUserChoice.ready(), true);

    state.deviceModal.configurations = {};
    assert.equal(CONFIG_INPUTS.scaleUserChoice.ready(), false);
});
