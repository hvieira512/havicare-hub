import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    apiUserFilterControls as buildFilterControls,
    apiUserForm as buildForm,
    licenseRefIdFor,
} from "../../src/Dashboard/dashboard/settings/api-users.js";

// Os construtores devolvem um fragmento de marcação; as assertivas de texto querem texto.
const apiUserForm = (...args) => String(buildForm(...args));
const apiUserFilterControls = (...args) => String(buildFilterControls(...args));

const admin = { id: 1, username: "havicare-platform", role: "hub_admin", enabled: 1 };
const client = {
    id: 2,
    username: "cliente",
    role: "license_client",
    enabled: 0,
    company_name: "havicare",
    license_id: "22",
};

/** Os filtros saem do mesmo descritor das colunas e ficam por cima da lista. */
const COLUMNS = [
    { field: "username", filter: { type: "text", param: "username" } },
    {
        field: "role",
        filter: {
            type: "select",
            param: "role",
            options: [{ value: "hub_admin", count: 2 }, { value: "license_client", count: 11 }],
        },
    },
    { field: "company_name", filter: null },
    {
        field: "enabled",
        filter: {
            type: "select",
            param: "enabled",
            options: [{ value: "1", count: 12 }, { value: "0", count: 1 }],
        },
    },
];

test("a busca e os filtros de perfil e estado vêm do descritor", () => {
    const bar = apiUserFilterControls(COLUMNS, { username: "hav", role: "hub_admin", enabled: "" });

    assert.match(bar, /type="search"/);
    assert.match(bar, /data-filter="username"/);
    assert.match(bar, /value="hav"/);
    assert.match(bar, /<select[^>]*data-filter="role"/);
    assert.match(bar, /<select[^>]*data-filter="enabled"/);
    assert.match(bar, /Todos os perfis/);
    assert.match(bar, /Todos os estados/);
});

test("as opções trazem a etiqueta em português e a contagem da faceta", () => {
    const bar = apiUserFilterControls(COLUMNS, {});

    assert.match(bar, /<option value="hub_admin">Administrador \(2\)</);
    assert.match(bar, /<option value="license_client">Cliente \(11\)</);
    assert.match(bar, /<option value="1">Ativo \(12\)</);
    assert.match(bar, /<option value="0">Pausado \(1\)</);
});

/** Sem marcar o escolhido, o controlo diria «Todos» com a lista estreitada. */
test("o valor escolhido fica selecionado", () => {
    const bar = apiUserFilterControls(COLUMNS, { role: "hub_admin" });

    assert.match(bar, /<option value="hub_admin" selected>/);
});

test("uma coluna sem filtro não aparece na barra", () => {
    assert.doesNotMatch(apiUserFilterControls(COLUMNS, {}), /company_name/);
});

const LICENSES = [
    { id: 7, company_name: "havicare", license_id: 22, name: "Lar do Sol" },
    { id: 9, company_name: "hitcare", license_id: 3, name: "Casa Azul" },
];

/** Criar e editar são o mesmo formulário: o que muda é o que vem preenchido. */
test("editar traz o utilizador dentro do formulário", () => {
    const form = apiUserForm({ ...client, license_ref_id: 7 }, LICENSES);

    assert.match(form, /data-id="2"/);
    assert.match(form, /data-field="username" value="cliente"/);
    assert.match(form, /<option value="license_client" selected>/);
    assert.match(form, /<option value="7" selected>/);
    assert.match(form, />Guardar</);
});

/** Nasce cliente, que é o perfil que a maioria leva, e sem licença nenhuma escolhida. */
test("criar nasce vazio e no perfil de cliente", () => {
    const form = apiUserForm(null, LICENSES);

    assert.match(form, /data-id=""/);
    assert.match(form, /data-field="username" value=""/);
    assert.match(form, /<option value="license_client" selected>/);
    assert.doesNotMatch(form, /<option value="7" selected>/);
    assert.match(form, />Criar</);
});

/** A password só se pede a criar: trocá-la é outro verbo do mesmo menu. */
test("só o formulário de criar pede palavra-passe", () => {
    assert.match(apiUserForm(null, LICENSES), /data-field="password"/);
    assert.doesNotMatch(apiUserForm(client, LICENSES), /data-field="password"/);
});

/** Um admin manda em todas as licenças, e a escolha de uma não se lhe aplica. */
test("a licença de um administrador entra desligada", () => {
    assert.match(apiUserForm(admin, LICENSES), /data-field="licenseRefId" disabled/);
    assert.doesNotMatch(apiUserForm(client, LICENSES), /data-field="licenseRefId" disabled/);
});

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

/**
 * O binder recusa a string em vez de a converter (`(int)"abc"` dá `0`, que tem significado nas
 * licenças); o `<select>` vazio dá `""`, e é aqui que passa a `null`.
 */
test("um admin do hub não fica preso a licença nenhuma", () => {
    assert.equal(licenseRefIdFor("hub_admin", ""), null);
    assert.equal(licenseRefIdFor("hub_admin", "1001"), null);
    assert.equal(licenseRefIdFor("hub_admin", 1001), null);
});

test("a licença de um cliente sai como inteiro e não como texto", () => {
    assert.equal(licenseRefIdFor("license_client", "1001"), 1001);
    assert.equal(licenseRefIdFor("license_client", 1001), 1001);
});

test("um cliente sem licença escolhida manda null, e não a string vazia", () => {
    assert.equal(licenseRefIdFor("license_client", ""), null);
    assert.equal(licenseRefIdFor("license_client", null), null);
    assert.equal(licenseRefIdFor("license_client", undefined), null);
});
