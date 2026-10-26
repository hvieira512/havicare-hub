import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import {
    loadedCellsRunoutText,
    syncLoadedCellsRunout,
} from "../../src/Dashboard/dashboard/devices/config/loaded-cells-runout.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { state } from "../../src/Dashboard/dashboard/core/state.js";

const now = new Date(2026, 9, 1, 10, 52);

const DISPENSER = "861265061009822";

function withDetail(detail, run, modalImei = DISPENSER) {
    const previous = [state.selectedDetail, state.deviceModal.imei];
    state.selectedDetail = detail;
    state.deviceModal.imei = modalImei;
    try {
        run();
    } finally {
        [state.selectedDetail, state.deviceModal.imei] = previous;
    }
}

const detail = (overrides = {}) => ({
    device: { imei: DISPENSER },
    effectiveConfigurations: {
        medication_reminders: {
            plans: [
                { times: [{ time: "10:47", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
                { times: [{ time: "10:50", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
            ],
        },
        ...overrides.effectiveConfigurations,
    },
    recent: {
        telemetry: [
            { type: "battery", occurredAt: "2026-10-01T09:50:00Z", data: { percent: 99 } },
            { type: "cells_remaining", occurredAt: "2026-10-01T09:49:47Z", data: { current: 3, remaining: 3 } },
            { type: "cells_remaining", occurredAt: "2026-10-01T09:46:47Z", data: { current: 2, remaining: 4 } },
        ],
        ...overrides.recent,
    },
});

test("the sentence combines the value being typed with the tray position", () => {
    withDetail(detail(), () => {
        assert.equal(
            loadedCellsRunoutText(6, now),
            "Com 2 doses por dia, acaba sáb., 3 out. às 10:47.",
        );
        assert.equal(
            loadedCellsRunoutText(4, now),
            "Com 2 doses por dia, acaba amanhã às 10:47.",
        );
    });
});

test("the position comes from the latest reading, not the first in the list", () => {
    withDetail(detail(), () => {
        // Com a posição 2 a frase daria mais um dia; é a leitura das 09:49 que vale.
        assert.equal(loadedCellsRunoutText(5, now), "Com 2 doses por dia, acaba amanhã às 10:50.");
    });
});

test("loading below the position leaves nothing to dispense", () => {
    withDetail(detail(), () => {
        assert.equal(
            loadedCellsRunoutText(3, now),
            "Sem doses por dispensar a partir do compartimento 3.",
        );
        assert.equal(
            loadedCellsRunoutText(1, now),
            "Sem doses por dispensar a partir do compartimento 3.",
        );
    });
});

test("the sentence reads the device the modal shows, not the one selected behind it", () => {
    withDetail(detail(), () => {
        assert.equal(loadedCellsRunoutText(6, now), "");
    }, "351266770073676");
});

test("without a position or a typed number, nothing is said", () => {
    withDetail(detail({ recent: { telemetry: [] } }), () => {
        assert.equal(loadedCellsRunoutText(6, now), "");
    });

    withDetail(detail(), () => {
        assert.equal(loadedCellsRunoutText(null, now), "");
        assert.equal(loadedCellsRunoutText(Number.NaN, now), "");
    });
});

const LOADED_CELLS = {
    key: "loaded_cells",
    capabilityKey: "loaded_cells",
    command: "loadedCells",
    label: "Carregado até ao compartimento",
    input: "number",
    fields: ["cells"],
    category: "health",
    options: { min: 0, max: 28, label: "de 28" },
};

const renderLoadedCells = () => parseFragment(renderDeviceConfigurationRoot({
    protocol: "zayata-m228",
    catalog: [LOADED_CELLS],
    capabilityCatalog: [{
        key: "loaded_cells",
        deviceType: "pill_dispenser",
        section: "health",
        sectionLabel: "Saúde",
        isConfigurable: true,
        isRequestable: false,
    }],
    configurations: { loaded_cells: { cells: 6 } },
    configurationSync: { entries: {} },
    capabilities: {},
    uiByKey: {},
    actionDeliveries: {},
    activeCategory: "health",
    online: true,
}));

test("the date line is drawn under the field", () => {
    withDetail(detail(), () => {
        const root = renderLoadedCells();
        const line = root.querySelector("[data-loaded-runout]");

        assert.ok(line, "o campo tem de trazer a linha da data");
        assert.match(line.textContent, /Com 2 doses por dia, acaba /);
    });
});

test("typing in the field rewrites the sentence without redrawing the row", () => {
    withDetail(detail(), () => {
        const root = parseFragment(`
            <section data-config-section data-config-key="loaded_cells">
                <div data-loaded-runout>Com 2 doses por dia, acaba amanhã às 10:47.</div>
                <input data-config-field="cells" value="6">
            </section>`);
        const input = root.querySelector("[data-config-field=\"cells\"]");

        input.value = "3";
        syncLoadedCellsRunout(input);
        assert.equal(
            root.querySelector("[data-loaded-runout]").textContent,
            "Sem doses por dispensar a partir do compartimento 3.",
        );

        input.value = "";
        syncLoadedCellsRunout(input);
        assert.equal(root.querySelector("[data-loaded-runout]").textContent, "");
    });
});
