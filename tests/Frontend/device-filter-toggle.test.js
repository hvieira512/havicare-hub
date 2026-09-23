import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { FILTERS_STORAGE_KEY } = await import("../../src/Dashboard/dashboard/storage.js");
const { handleDeviceFilterClick, initListFilters } =
    await import("../../src/Dashboard/dashboard/devices/list-filters.js");

/**
 * O que um clique numa caixa de filtro faz ao estado, sem passar pelo desenho.
 *
 * As árvores desenham o filho marcado quando o pai está marcado, mas quem está na lista é só o
 * pai: um clique num filho marcado é «troca o pai pelos irmãos», e marcar o pai tem de apagar
 * os filhos marcados à parte. Nada disto se vê no ecrã antes de estar errado.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("div");
        return target[name];
    },
});

let changes;

/** O clique como o `handleDeviceFilterClick` o lê: um botão com as duas datas. */
const filterClick = (key, value) => {
    const button = document.createElement("button");
    button.dataset.action = "toggleDeviceFilter";
    button.dataset.filterKey = key;
    button.dataset.filterValue = value;

    return handleDeviceFilterClick({ target: button });
};

const storedFilters = () => JSON.parse(localStorage.getItem(FILTERS_STORAGE_KEY));

beforeEach(() => {
    changes = 0;
    initListFilters({
        els,
        onChange: () => {
            changes += 1;
        },
    });
    localStorage.removeItem(FILTERS_STORAGE_KEY);
    state.deviceFilters = {
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    };
    state.summary.deviceFilterCounts = {
        deviceType: [],
        supplierModels: {
            suppliers: [
                {
                    supplier: "veepoo",
                    count: 5,
                    models: [{ model: "VL17", count: 3 }, { model: "MF91", count: 2 }],
                },
                // Um só modelo: é com ele que se desmarca o último de um fornecedor.
                { supplier: "qinglanst", count: 4, models: [{ model: "R60", count: 4 }] },
            ],
        },
        license: {
            none: 2,
            companies: [
                {
                    company: "hitcare",
                    count: 6,
                    licenses: [{ licenseId: 1, count: 4 }, { licenseId: 2, count: 2 }],
                },
                { company: "outra", count: 3, licenses: [{ licenseId: 9, count: 3 }] },
            ],
        },
    };
});

test("marcar e desmarcar um tipo põe e tira o valor da lista", async () => {
    await filterClick("deviceType", "watch");
    assert.deepEqual(state.deviceFilters.deviceType, ["watch"]);

    await filterClick("deviceType", "radar");
    assert.deepEqual(state.deviceFilters.deviceType, ["watch", "radar"]);

    await filterClick("deviceType", "watch");
    assert.deepEqual(state.deviceFilters.deviceType, ["radar"]);
});

test("cada clique guarda os filtros e volta a pedir a lista", async () => {
    await filterClick("supplier", "veepoo");

    assert.deepEqual(storedFilters().supplier, ["veepoo"]);
    assert.equal(changes, 1);
});

test("marcar o fornecedor absorve os modelos dele já marcados", async () => {
    state.deviceFilters.model = ["VL17", "R60"];

    await filterClick("supplier", "veepoo");

    assert.deepEqual(state.deviceFilters.supplier, ["veepoo"]);
    // O R60 é de outro fornecedor e fica onde estava.
    assert.deepEqual(state.deviceFilters.model, ["R60"]);
});

test("desmarcar o fornecedor não devolve os modelos que ele absorveu", async () => {
    state.deviceFilters.model = ["VL17"];

    await filterClick("supplier", "veepoo");
    await filterClick("supplier", "veepoo");

    assert.deepEqual(state.deviceFilters.supplier, []);
    assert.deepEqual(state.deviceFilters.model, []);
});

test("clicar num modelo coberto pelo fornecedor troca o fornecedor pelos irmãos", async () => {
    state.deviceFilters.supplier = ["veepoo"];

    await filterClick("model", "VL17");

    assert.deepEqual(state.deviceFilters.supplier, []);
    assert.deepEqual(state.deviceFilters.model, ["MF91"]);
});

test("a troca pelos irmãos não mexe nos outros fornecedores marcados", async () => {
    state.deviceFilters.supplier = ["veepoo", "qinglanst"];

    await filterClick("model", "VL17");

    assert.deepEqual(state.deviceFilters.supplier, ["qinglanst"]);
    assert.deepEqual(state.deviceFilters.model, ["MF91"]);
});

/** Um fornecedor de um só modelo não tem irmãos: o filtro fica vazio, que quer dizer tudo. */
test("desmarcar o último modelo de um fornecedor limpa os dois filtros", async () => {
    state.deviceFilters.supplier = ["qinglanst"];

    await filterClick("model", "R60");

    assert.deepEqual(state.deviceFilters.supplier, []);
    assert.deepEqual(state.deviceFilters.model, []);
});

test("marcar os modelos de um fornecedor um a um não marca o fornecedor", async () => {
    await filterClick("model", "VL17");
    await filterClick("model", "MF91");

    assert.deepEqual(state.deviceFilters.model, ["VL17", "MF91"]);
    assert.deepEqual(state.deviceFilters.supplier, []);

    await filterClick("model", "VL17");
    assert.deepEqual(state.deviceFilters.model, ["MF91"]);
});

test("marcar a empresa apaga as licenças dela marcadas à parte", async () => {
    state.deviceFilters.license = ["hitcare:1", "outra:9"];

    await filterClick("license", "hitcare");

    assert.deepEqual(state.deviceFilters.license, ["outra:9", "hitcare"]);
});

test("desmarcar a empresa não devolve as licenças que ela apagou", async () => {
    state.deviceFilters.license = ["hitcare:1"];

    await filterClick("license", "hitcare");
    await filterClick("license", "hitcare");

    assert.deepEqual(state.deviceFilters.license, []);
});

test("clicar numa licença de uma empresa inteira troca a empresa pelas irmãs", async () => {
    state.deviceFilters.license = ["hitcare"];

    await filterClick("license", "hitcare:1");

    assert.deepEqual(state.deviceFilters.license, ["hitcare:2"]);
});

test("desmarcar a última licença de uma empresa limpa o filtro", async () => {
    state.deviceFilters.license = ["outra"];

    await filterClick("license", "outra:9");

    assert.deepEqual(state.deviceFilters.license, []);
});

test("com a empresa por marcar, a licença é só mais um valor", async () => {
    await filterClick("license", "hitcare:1");
    assert.deepEqual(state.deviceFilters.license, ["hitcare:1"]);

    await filterClick("license", "hitcare:1");
    assert.deepEqual(state.deviceFilters.license, []);
});

/** O «sem licença» é folha e não empresa: marcar uma empresa não lhe toca. */
test("o «sem licença» marca-se e desmarca-se sozinho", async () => {
    await filterClick("license", "none");
    await filterClick("license", "hitcare");

    assert.deepEqual(state.deviceFilters.license, ["none", "hitcare"]);

    await filterClick("license", "none");
    assert.deepEqual(state.deviceFilters.license, ["hitcare"]);
});

/**
 * As contagens chegam com a listagem, e o painel já responde a cliques antes disso -- um
 * filtro reposto do armazenamento desenha-se sem árvore nenhuma.
 */
test("sem contagens carregadas, o clique acrescenta o valor e mais nada", async () => {
    state.summary.deviceFilterCounts = { deviceType: [], license: { companies: [], none: 0 } };
    state.deviceFilters.supplier = ["veepoo"];

    await filterClick("model", "VL17");

    assert.deepEqual(state.deviceFilters.model, ["VL17"]);
    assert.deepEqual(state.deviceFilters.supplier, ["veepoo"]);
});

test("uma chave que não é filtro não mexe no estado", async () => {
    await filterClick("naoExiste", "x");

    assert.deepEqual(state.deviceFilters.deviceType, []);
    assert.equal(changes, 0);
});
