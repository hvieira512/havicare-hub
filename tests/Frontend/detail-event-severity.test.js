import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { allDetailItems, detailFilterChipLabels, filterDetailItems } =
    await import("../../src/Dashboard/dashboard/devices/detail-filters.js");
const { telemetryActivityRow } = await import("../../src/Dashboard/dashboard/devices/detail.js");

const event = (type, severity, data = {}) => ({
    type,
    ...(severity ? { severity } : {}),
    occurredAt: "2026-10-08T10:00:00Z",
    data,
});

beforeEach(() => {
    state.selectedImei = "aaa111";
    state.detailFilters = { from: "", to: "", type: "all", severity: "all", q: "" };
});

/** A lista não escolhe tipos à mão: um evento que o hub classificou tem lugar nela. */
test("todo o evento com gravidade entra no histórico", () => {
    state.selectedDetail = {
        model: { deviceType: "diaper_sensor" },
        recent: {
            events: [
                event("change_required", "alarm", { previousState: "clean" }),
                event("check_required", "alert", { previousState: "clean" }),
                event("storage_environment", "alert", { outOfRange: true }),
                event("medication_alarm_change", "info", { alarm: 1, state: "taken" }),
                event("low_battery", "alert"),
            ],
        },
    };

    assert.deepEqual(
        allDetailItems().map((item) => item.payload.type),
        ["change_required", "check_required", "storage_environment", "medication_alarm_change", "low_battery"],
    );
});

/** O histórico guardado antes da gravidade tem o formato antigo, e esse já não se desenha. */
test("um evento sem gravidade fica de fora", () => {
    state.selectedDetail = {
        model: { deviceType: "watch" },
        recent: { events: [event("alarm", null, { reason: "sos" })] },
    };

    assert.deepEqual(allDetailItems(), []);
});

test("a cor da linha é a da gravidade", () => {
    assert.equal(telemetryActivityRow(event("zone_exit", "alert", { zone: "geofence" })).tone, "warning");
    assert.equal(telemetryActivityRow(event("low_battery", "alert")).tone, "warning");
    assert.equal(telemetryActivityRow(event("fall", "alarm", { confirmed: true })).tone, "danger");
    // Sem perigo nem alerta, fica a cor da capacidade: uma entrada na divisão não é vermelha.
    assert.equal(telemetryActivityRow(event("zone_entry", "info", { zone: "room" })).tone, "info");
});

test("o filtro de gravidade deixa só os alarmes, ou só os alertas", () => {
    const items = [
        event("fall", "alarm", { confirmed: true }),
        event("low_battery", "alert"),
        event("zone_entry", "info", { zone: "room" }),
        { type: "battery", occurredAt: "2026-10-08T10:00:00Z", data: { percent: 80 } },
    ].map((payload) => ({ _source: payload.severity ? "event" : "telemetry", raw: payload, payload }));

    state.detailFilters = { ...state.detailFilters, severity: "alarm" };
    assert.deepEqual(filterDetailItems(items).map((item) => item.payload.type), ["fall"]);

    state.detailFilters = { ...state.detailFilters, severity: "alert" };
    assert.deepEqual(filterDetailItems(items).map((item) => item.payload.type), ["low_battery"]);
});

test("a pastilha do filtro de gravidade diz o que ficou", () => {
    assert.deepEqual(
        detailFilterChipLabels({ from: "", to: "", type: "all", severity: "alert", q: "" }),
        [{ key: "severity", label: "Alertas" }],
    );
});
