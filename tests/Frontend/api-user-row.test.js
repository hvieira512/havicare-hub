import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    apiUserFilterControls,
    apiUserForm,
    apiUserRow,
} from "../../src/Dashboard/dashboard/settings/api-users.js";

const admin = { id: 1, username: "havicare-platform", role: "hub_admin", enabled: 1 };
const client = {
    id: 2,
    username: "cliente",
    role: "license_client",
    enabled: 0,
    company_name: "havicare",
    license_id: "22",
};

/**
 * A coluna «Estado» da grelha encolhia até só sobrar o ponto verde, que não distingue ativo
 * de pausado.
 */
test("o estado sai por extenso e não só em cor", () => {
    assert.match(apiUserRow(admin), />Ativo</);
    assert.match(apiUserRow(client), />Pausado</);
});

test("o contexto do utilizador é uma linha de texto corrido", () => {
    assert.match(apiUserRow(admin), /Administrador · todas as licenças/);
    assert.match(apiUserRow(client), /Cliente · havicare \/ 22/);
});

/** O nome é o que pode ser longo, e o título repõe o que a reticência corta. */
test("o nome corta com reticências e guarda o inteiro no título", () => {
    const row = apiUserRow(admin);
    assert.match(row, /text-truncate/);
    assert.match(row, /title="havicare-platform"/);
});

/** Três botões só com ícone não se adivinham: os verbos ficam escritos. */
test("as ações da linha vivem num menu com os verbos escritos", () => {
    const row = apiUserRow(admin);
    assert.match(row, /data-bs-toggle="dropdown"/);
    assert.match(row, /aria-label="Ações de havicare-platform"/);
    assert.match(row, /data-action="changeApiUserPassword"[^>]*>Trocar palavra-passe</);
    assert.match(row, /dropdown-divider/);
    assert.match(row, /text-danger[^>]*data-action="deleteApiUser"[^>]*>Eliminar utilizador</);
});

/** Mudar o perfil ou a licença é o que a edição em célula dava, e não pode ficar sem caminho. */
test("o menu abre pela edição do utilizador", () => {
    const row = apiUserRow(admin);

    assert.match(row, /data-action="editApiUser"[^>]*>Editar utilizador</);
    assert.ok(row.indexOf("Editar utilizador") < row.indexOf("Trocar palavra-passe"));
});

test("o verbo da pausa segue o estado do utilizador", () => {
    assert.match(apiUserRow(admin), />Pausar acesso</);
    assert.match(apiUserRow(client), />Retomar acesso</);
});

/** O que a grelha dava no cabeçalho passa para cima da lista, do mesmo descritor. */
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

/** O filtro escolhido fica marcado, senão o controlo dizia «Todos» com a lista estreitada. */
test("o valor escolhido fica selecionado", () => {
    const bar = apiUserFilterControls(COLUMNS, { role: "hub_admin" });

    assert.match(bar, /<option value="hub_admin" selected>/);
});

/** Uma coluna sem filtro no descritor não gera controlo nenhum. */
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
