import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { allDetailItems, detailFilterChipLabels, filterDetailItems } =
    await import("../../src/Dashboard/dashboard/devices/detail/detail-filters.js");

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
test("every event with a severity enters the history", () => {
    state.selectedDetail = {
        model: { deviceType: "diaper_sensor" },
        recent: {
            events: [
                event("change_required", "alarm", { previousState: "clean" }),
                event("check_required", "alert", { previousState: "clean" }),
                event("storage_environment", "alert", { outOfRange: true }),
                event("medication_alarm_change", "info", { slot: 1, state: "taken" }),
                event("low_battery", "alert"),
            ],
        },
    };

    assert.deepEqual(
        allDetailItems().map((item) => item.payload.type),
        ["change_required", "check_required", "storage_environment", "medication_alarm_change", "low_battery"],
    );
});

/** Sem gravidade não é um acontecimento de domínio, e não tem lugar no histórico. */
test("an event without a severity stays out", () => {
    state.selectedDetail = {
        model: { deviceType: "watch" },
        recent: { events: [event("alarm", null, { reason: "sos" })] },
    };

    assert.deepEqual(allDetailItems(), []);
});

test("the severity filter keeps only alarms, or only alerts", () => {
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

test("the severity filter chip says what remains", () => {
    assert.deepEqual(
        detailFilterChipLabels({ from: "", to: "", type: "all", severity: "alert", q: "" }),
        [{ key: "severity", label: "Alertas" }],
    );
});
