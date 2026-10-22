import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { FILTERS_STORAGE_KEY } = await import("../../src/Dashboard/dashboard/storage.js");
const { handleDeviceFilterClick } =
    await import("../../src/Dashboard/dashboard/devices/list-filters.js");

/**
 * Quem está na lista é só o pai marcado: um clique num filho é «troca o pai pelos irmãos», e
 * marcar o pai apaga os filhos marcados à parte.
 */
const els = autoEls();

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
    cacheElements(els);
    // Pedir a lista outra vez é o que um filtro faz: conta-se o pedido.
    globalThis.fetch = async (url) => {
        if (new URL(url, "http://localhost").pathname === "/api/devices") changes += 1;
        return Response.json({ data: [] });
    };
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
                { company: "other", count: 3, licenses: [{ licenseId: 9, count: 3 }] },
            ],
        },
    };
});

test("ticking and unticking a type adds and removes the value from the list", async () => {
    await filterClick("deviceType", "watch");
    assert.deepEqual(state.deviceFilters.deviceType, ["watch"]);

    await filterClick("deviceType", "radar");
    assert.deepEqual(state.deviceFilters.deviceType, ["watch", "radar"]);

    await filterClick("deviceType", "watch");
    assert.deepEqual(state.deviceFilters.deviceType, ["radar"]);
});

test("each click saves the filters and requests the list again", async () => {
    await filterClick("supplier", "veepoo");

    assert.deepEqual(storedFilters().supplier, ["veepoo"]);
    assert.equal(changes, 1);
});

test("ticking the supplier absorbs its models already ticked", async () => {
    state.deviceFilters.model = ["VL17", "R60"];

    await filterClick("supplier", "veepoo");

    assert.deepEqual(state.deviceFilters.supplier, ["veepoo"]);
    // O R60 é de outro fornecedor e fica onde estava.
    assert.deepEqual(state.deviceFilters.model, ["R60"]);
});

test("unticking the supplier does not bring back the models it absorbed", async () => {
    state.deviceFilters.model = ["VL17"];

    await filterClick("supplier", "veepoo");
    await filterClick("supplier", "veepoo");

    assert.deepEqual(state.deviceFilters.supplier, []);
    assert.deepEqual(state.deviceFilters.model, []);
});

test("clicking a model covered by the supplier swaps the supplier for the siblings", async () => {
    state.deviceFilters.supplier = ["veepoo"];

    await filterClick("model", "VL17");

    assert.deepEqual(state.deviceFilters.supplier, []);
    assert.deepEqual(state.deviceFilters.model, ["MF91"]);
});

test("the swap for siblings leaves the other ticked suppliers alone", async () => {
    state.deviceFilters.supplier = ["veepoo", "qinglanst"];

    await filterClick("model", "VL17");

    assert.deepEqual(state.deviceFilters.supplier, ["qinglanst"]);
    assert.deepEqual(state.deviceFilters.model, ["MF91"]);
});

/** Um fornecedor de um só modelo não tem irmãos: o filtro fica vazio, que quer dizer tudo. */
test("unticking a supplier's last model clears both filters", async () => {
    state.deviceFilters.supplier = ["qinglanst"];

    await filterClick("model", "R60");

    assert.deepEqual(state.deviceFilters.supplier, []);
    assert.deepEqual(state.deviceFilters.model, []);
});

test("ticking a supplier's models one by one does not tick the supplier", async () => {
    await filterClick("model", "VL17");
    await filterClick("model", "MF91");

    assert.deepEqual(state.deviceFilters.model, ["VL17", "MF91"]);
    assert.deepEqual(state.deviceFilters.supplier, []);

    await filterClick("model", "VL17");
    assert.deepEqual(state.deviceFilters.model, ["MF91"]);
});

test("ticking the company erases its licences ticked separately", async () => {
    state.deviceFilters.license = ["hitcare:1", "other:9"];

    await filterClick("license", "hitcare");

    assert.deepEqual(state.deviceFilters.license, ["other:9", "hitcare"]);
});

test("unticking the company does not bring back the licences it erased", async () => {
    state.deviceFilters.license = ["hitcare:1"];

    await filterClick("license", "hitcare");
    await filterClick("license", "hitcare");

    assert.deepEqual(state.deviceFilters.license, []);
});

test("clicking a licence of a whole company swaps the company for the siblings", async () => {
    state.deviceFilters.license = ["hitcare"];

    await filterClick("license", "hitcare:1");

    assert.deepEqual(state.deviceFilters.license, ["hitcare:2"]);
});

test("unticking a company's last licence clears the filter", async () => {
    state.deviceFilters.license = ["other"];

    await filterClick("license", "other:9");

    assert.deepEqual(state.deviceFilters.license, []);
});

test("with the company unticked, the licence is just another value", async () => {
    await filterClick("license", "hitcare:1");
    assert.deepEqual(state.deviceFilters.license, ["hitcare:1"]);

    await filterClick("license", "hitcare:1");
    assert.deepEqual(state.deviceFilters.license, []);
});

/** O «sem licença» é folha e não empresa: marcar uma empresa não lhe toca. */
test("«sem licença» ticks and unticks on its own", async () => {
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
test("without counts loaded, the click adds the value and nothing else", async () => {
    state.summary.deviceFilterCounts = { deviceType: [], license: { companies: [], none: 0 } };
    state.deviceFilters.supplier = ["veepoo"];

    await filterClick("model", "VL17");

    assert.deepEqual(state.deviceFilters.model, ["VL17"]);
    assert.deepEqual(state.deviceFilters.supplier, ["veepoo"]);
});

test("a key that is not a filter leaves the state alone", async () => {
    await filterClick("naoExiste", "x");

    assert.deepEqual(state.deviceFilters.deviceType, []);
    assert.equal(changes, 0);
});
