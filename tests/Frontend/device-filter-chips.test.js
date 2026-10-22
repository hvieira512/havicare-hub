import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const {
    deviceFilterChipLabels,
    handleDeviceFilterChipRemove,
} = await import("../../src/Dashboard/dashboard/devices/list-filters.js");

/** Os filtros sobrevivem à sessão, e as pastilhas por cima da lista dizem quais estão aplicados. */
const els = autoEls();

let changes;

/** O `×` como o `handleDeviceFilterChipRemove` o lê: o grupo e o valor numa chave só. */
const removeChip = (key) => {
    const button = document.createElement("button");
    button.dataset.action = "removeDeviceFilter";
    button.dataset.filterKey = key;

    return handleDeviceFilterChipRemove({ target: button });
};

beforeEach(() => {
    changes = 0;
    cacheElements(els);
    // Pedir a lista outra vez é o que um filtro faz: conta-se o pedido.
    globalThis.fetch = async (url) => {
        if (new URL(url, "http://localhost").pathname === "/api/devices") changes += 1;
        return Response.json({ data: [] });
    };
    state.licenses = [];
    state.deviceFilters = {
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    };
    state.summary.deviceFilterCounts = {
        deviceType: [],
        supplierModels: { suppliers: [] },
        license: { companies: [], none: 0 },
    };
});

test("each applied filter gives a chip, with the group and value in the key", () => {
    state.deviceFilters = {
        deviceType: ["watch"],
        supplier: ["veepoo"],
        model: ["VL17"],
        license: ["none", "hitcare", "hitcare:1"],
        online: true,
    };

    assert.deepEqual(deviceFilterChipLabels(state.deviceFilters), [
        { key: "online:online", label: "Ligados" },
        { key: "deviceType:watch", label: "Relógio" },
        { key: "supplier:veepoo", label: "veepoo" },
        { key: "model:VL17", label: "VL17" },
        { key: "license:none", label: "Sem licença" },
        { key: "license:hitcare", label: "hitcare" },
        { key: "license:hitcare:1", label: "1" },
    ]);
});

test("without filters there are no chips", () => {
    assert.deepEqual(deviceFilterChipLabels(state.deviceFilters), []);
});

test("offline devices get a chip too", () => {
    assert.deepEqual(deviceFilterChipLabels({ ...state.deviceFilters, online: false }), [
        { key: "online:offline", label: "Desligados" },
    ]);
});

test("the × removes that filter and requests the list again", async () => {
    state.deviceFilters.deviceType = ["watch", "radar"];

    await removeChip("deviceType:watch");

    assert.deepEqual(state.deviceFilters.deviceType, ["radar"]);
    assert.equal(changes, 1);
});

test("the × on status resets all", async () => {
    state.deviceFilters.online = true;

    await removeChip("online:online");

    assert.equal(state.deviceFilters.online, null);
    assert.equal(changes, 1);
});

/** O valor de uma licença já leva dois pontos: quem parte a chave parte-a no primeiro. */
test("the × on a licence removes the licence and not the company", async () => {
    state.deviceFilters.license = ["hitcare", "hitcare:1"];

    await removeChip("license:hitcare:1");

    assert.deepEqual(state.deviceFilters.license, ["hitcare"]);
});
