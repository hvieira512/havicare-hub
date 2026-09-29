import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initListFilters, renderDeviceFilterControls } =
    await import("../../src/Dashboard/dashboard/devices/list-filters.js");

/**
 * O botão do rodapé do painel de filtros, que diz quantos dispositivos ficam.
 *
 * No telemóvel o painel cobre a lista: sem este número, filtra-se às cegas e só se sabe o
 * efeito depois de fechar.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("div");
        return target[name];
    },
});

beforeEach(() => {
    initListFilters({ els, onChange: () => {} });
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

const labelForTotal = (total) => {
    state.summary.devicePagination = { total };
    renderDeviceFilterControls();

    return els.applyDeviceFiltersBtn.textContent;
};

test("o botão diz quantos dispositivos passam os filtros", () => {
    assert.equal(labelForTotal(4), "Ver 4 dispositivos");
});

test("um só dispositivo não leva plural", () => {
    assert.equal(labelForTotal(1), "Ver 1 dispositivo");
});

/** «Ver 0 dispositivos» é um convite a carregar num botão que não mostra nada. */
test("sem resultados o botão não convida a ver", () => {
    assert.equal(labelForTotal(0), "Nenhum dispositivo");
});
