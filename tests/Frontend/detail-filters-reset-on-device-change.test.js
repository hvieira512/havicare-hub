import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state, selectImei } = await import("../../src/Dashboard/dashboard/state.js");
const { initDetailFilters, populateDetailFilterTypes } =
    await import("../../src/Dashboard/dashboard/devices/detail-filters.js");

/**
 * Filtros herdados do aparelho anterior escondem as leituras do seguinte, e o painel diria
 * «Ainda não há leituras».
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) {
            target[name] = document.createElement(
                name === "detailFilterType"
                    ? "select"
                    : name.startsWith("detailFilter") || name === "detailSearch"
                        ? "input"
                        : "div",
            );
        }
        return target[name];
    },
});

const detailWith = (types) => ({
    recent: {
        telemetry: types.map((type, index) => ({
            type,
            seq: index + 1,
            occurredAt: "2026-10-01T09:00:00Z",
            data: {},
        })),
        events: [],
        commands: [],
        connection: [],
    },
});

beforeEach(() => {
    initDetailFilters({ els, onChange: () => {} });
    state.selectedImei = "aaa111";
    state.detailFilters = { from: "", to: "", type: "blood_pressure", q: "78" };
    state.detailFiltersDraft = { ...state.detailFilters };
});

test("escolher outro dispositivo larga os filtros aplicados ao anterior", () => {
    selectImei("bbb222");

    assert.deepEqual(state.detailFilters, { from: "", to: "", type: "all", q: "" });
    assert.deepEqual(state.detailFiltersDraft, { from: "", to: "", type: "all", q: "" });
});

test("voltar a escolher o mesmo dispositivo não mexe nos filtros", () => {
    selectImei("aaa111");

    assert.equal(state.detailFilters.type, "blood_pressure");
});

test("o selector de tipo larga as opções que o dispositivo novo não tem", () => {
    state.selectedDetail = detailWith(["blood_pressure", "heart_rate"]);
    populateDetailFilterTypes();
    assert.deepEqual(
        [...els.detailFilterType.options].map((option) => option.value),
        ["all", "blood_pressure", "heart_rate"],
    );

    selectImei("bbb222");
    state.selectedDetail = detailWith(["presence"]);
    populateDetailFilterTypes();

    assert.deepEqual(
        [...els.detailFilterType.options].map((option) => option.value),
        ["all", "presence"],
    );
});
