import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const {
    deviceFilterChipLabels,
    handleDeviceFilterChipRemove,
    initListFilters,
} = await import("../../src/Dashboard/dashboard/devices/list-filters.js");

/**
 * As pastilhas dos filtros aplicados, por cima da lista.
 *
 * Os filtros sobrevivem à sessão, e sem nada no ecrã a dizer quais são, dois herdados de
 * ontem ficam invisíveis.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("div");
        return target[name];
    },
});

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
    initListFilters({
        els,
        onChange: () => {
            changes += 1;
        },
    });
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

test("cada filtro aplicado dá uma pastilha, com o grupo e o valor na chave", () => {
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

test("sem filtros não há pastilhas", () => {
    assert.deepEqual(deviceFilterChipLabels(state.deviceFilters), []);
});

test("os desligados também têm pastilha", () => {
    assert.deepEqual(deviceFilterChipLabels({ ...state.deviceFilters, online: false }), [
        { key: "online:offline", label: "Desligados" },
    ]);
});

test("o × tira esse filtro e volta a pedir a lista", async () => {
    state.deviceFilters.deviceType = ["watch", "radar"];

    await removeChip("deviceType:watch");

    assert.deepEqual(state.deviceFilters.deviceType, ["radar"]);
    assert.equal(changes, 1);
});

test("o × do estado repõe todos", async () => {
    state.deviceFilters.online = true;

    await removeChip("online:online");

    assert.equal(state.deviceFilters.online, null);
    assert.equal(changes, 1);
});

/** O valor de uma licença já leva dois pontos: quem parte a chave parte-a no primeiro. */
test("o × de uma licença tira a licença e não a empresa", async () => {
    state.deviceFilters.license = ["hitcare", "hitcare:1"];

    await removeChip("license:hitcare:1");

    assert.deepEqual(state.deviceFilters.license, ["hitcare"]);
});
