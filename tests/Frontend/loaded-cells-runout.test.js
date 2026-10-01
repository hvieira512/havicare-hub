import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import {
    loadedCellsRunoutText,
    syncLoadedCellsRunout,
} from "../../src/Dashboard/dashboard/devices/config/loaded-cells-runout.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

const now = new Date(2026, 9, 1, 10, 52);

function withDetail(detail, run) {
    const previous = state.selectedDetail;
    state.selectedDetail = detail;
    try {
        run();
    } finally {
        state.selectedDetail = previous;
    }
}

const detail = (overrides = {}) => ({
    effectiveConfigurations: {
        medication_reminders: {
            plans: [
                { slot: 1, hour: 10, minute: 47 },
                { slot: 2, hour: 10, minute: 50 },
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

test("a frase cruza o valor que se está a escrever com a posição do prato", () => {
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

test("a posição vem da leitura mais recente e não da primeira da lista", () => {
    withDetail(detail(), () => {
        // Com a posição 2 a frase daria mais um dia; é a leitura das 09:49 que vale.
        assert.equal(loadedCellsRunoutText(5, now), "Com 2 doses por dia, acaba amanhã às 10:50.");
    });
});

test("carregar até abaixo da posição não deixa nada por dispensar", () => {
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

test("sem plano, sem posição ou sem número escrito, não se diz nada", () => {
    withDetail(detail({ effectiveConfigurations: { medication_reminders: { plans: [] } } }), () => {
        assert.equal(loadedCellsRunoutText(6, now), "");
    });

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

test("a linha da data é desenhada por baixo do campo", () => {
    withDetail(detail(), () => {
        const root = renderLoadedCells();
        const line = root.querySelector("[data-loaded-runout]");

        assert.ok(line, "o campo tem de trazer a linha da data");
        assert.match(line.textContent, /Com 2 doses por dia, acaba /);
    });
});

test("escrever no campo reescreve a frase sem redesenhar a linha", () => {
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

test("um período já terminado não deixa data, e a frase cala-se", () => {
    const ended = {
        effectiveConfigurations: {
            medication_reminders: {
                plans: [{ slot: 1, hour: 10, minute: 47 }],
            },
            medication_period: { enabled: true, startDate: "2026-09-01", endDate: "2026-09-30" },
        },
    };

    withDetail(detail(ended), () => {
        assert.equal(loadedCellsRunoutText(6, now), "");
    });
});
