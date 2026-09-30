import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { apiUserForm } from "../../src/Dashboard/dashboard/settings/api-users.js";

/**
 * O estado não está no formulário -- quem o muda é o verbo de pausar --, mas tem de viajar
 * com ele: procurá-lo na página carregada devolve `undefined` assim que a linha sai dela, e
 * um `PUT` com `enabled: false` pausa o utilizador sem o dizer.
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
