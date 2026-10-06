import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { apiUserForm as buildForm } from "../../src/Dashboard/dashboard/settings/api-users.js";

// O construtor devolve um fragmento de marcação; as assertivas de texto querem texto.
const apiUserForm = (...args) => String(buildForm(...args));

/**
 * O estado muda pelo verbo de pausar mas viaja com o formulário: lido da página, sai `undefined`
 * quando a linha já lá não está, e o `PUT` pausa o utilizador sem o dizer.
 */

const active = { id: 7, username: "gucc", role: "hub_admin", enabled: 1 };
const paused = { id: 8, username: "hitcare", role: "hub_admin", enabled: 0 };

test("o editor de um utilizador ativo declara-o ativo", () => {
    assert.match(apiUserForm(active, []), /data-enabled="1"/);
});

test("o editor de um utilizador pausado declara-o pausado", () => {
    assert.match(apiUserForm(paused, []), /data-enabled="0"/);
});

test("criar um utilizador não declara estado nenhum", () => {
    assert.doesNotMatch(apiUserForm(null, []), /data-enabled=/);
});
