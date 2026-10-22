import test from "node:test";
import assert from "node:assert/strict";

import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

const section = (entry, desired = {}) =>
    configSection(renderConfigInputs, entry, desired, { protocol: "wonlex-json" });

const sleepEntry = {
    key: "sleep_monitoring",
    capabilityKey: "sleep_monitoring",
    input: "wonlexSleepSettings",
    fields: ["enabled", "startTime", "endTime", "targetMinutes"],
};

const temperatureEntry = {
    key: "temperature_high_alert",
    capabilityKey: "temperature_high_alert",
    input: "wonlexReminderThreshold",
    fields: ["enabled", "thresholdCelsius"],
};

const lowHeartRateEntry = {
    key: "heart_rate_low_alert",
    capabilityKey: "heart_rate_low_alert",
    input: "wonlexHeartRateRange",
    fields: ["enabled", "thresholdBpm", "exerciseEnabled", "exerciseMinBpm", "exerciseMaxBpm", "exerciseThresholdBpm"],
};

test("sleep times draw as time fields and read back in HH:MM", () => {
    const root = section(sleepEntry, { enabled: true, startTime: "22:00", endTime: "07:00", targetMinutes: 480 });
    const start = root.querySelector("[data-config-field=\"startTime\"]");

    assert.equal(start.type, "time");
    assert.equal(start.value, "22:00");

    start.value = "23:30";
    assert.equal(readConfigPayload(root).startTime, "23:30");
});

test("a blank sleep time is left out of the payload", () => {
    const root = section(sleepEntry, { enabled: true, endTime: "07:00", targetMinutes: 480 });

    assert.deepEqual(readConfigPayload(root), { enabled: true, endTime: "07:00", targetMinutes: 480 });
});

test("the temperature threshold accepts decimals", () => {
    const root = section(temperatureEntry, { enabled: true, thresholdCelsius: 38.5 });
    const value = root.querySelector("[data-config-field=\"thresholdCelsius\"]");

    assert.equal(value.value, "38.5");
    assert.equal(value.step, "0.1");

    value.value = "37.8";
    assert.equal(readConfigPayload(root).thresholdCelsius, 37.8);
});

const bloodPressureEntry = {
    key: "blood_pressure_alert",
    capabilityKey: "blood_pressure_alert",
    input: "wonlexBloodPressureWarning",
    fields: ["enabled", "systolicMmHg", "diastolicMmHg"],
};

test("a blank heart rate limit is left out of the payload, not sent as zero", () => {
    const root = section(lowHeartRateEntry, { enabled: true, exerciseEnabled: true, exerciseMinBpm: 100 });

    assert.equal(root.querySelector("[data-config-field=\"thresholdBpm\"]").value, "");
    assert.deepEqual(readConfigPayload(root), { enabled: true, exerciseEnabled: true, exerciseMinBpm: 100 });
});

test("a blank blood pressure limit is left out of the payload", () => {
    const root = section(bloodPressureEntry, { enabled: true, systolicMmHg: 135 });

    assert.deepEqual(readConfigPayload(root), { enabled: true, systolicMmHg: 135 });
});

test("a blank temperature threshold is left out of the payload", () => {
    const root = section(temperatureEntry, { enabled: true });

    assert.deepEqual(readConfigPayload(root), { enabled: true });
});
