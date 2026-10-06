import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

const section = (entry, desired = {}) =>
    configSection(renderConfigInputs, entry, desired, { protocol: "wonlex-json" });

const sleepEntry = {
    key: "wonlexSleepIntervalOrSwitch",
    input: "wonlexSleepSettings",
    fields: ["switchState", "sleepStartTime", "sleepEndTime", "sleepTarget"],
};

const temperatureEntry = {
    key: "wonlexTemperatureExceedRemind",
    input: "wonlexReminderThreshold",
    fields: ["switchState", "RemindValue"],
};

const lowHeartRateEntry = {
    key: "wonlexHeartRateLowRemind",
    input: "wonlexHeartRateRange",
    fields: ["switchState", "remindValue", "exerciseSwitchState", "exerciseHRMin", "exerciseHRMax", "exerciseRemindValue"],
};

test("as horas do sono desenham-se como campo de hora", () => {
    const root = section(sleepEntry, { enabled: true, sleepStartTime: "220000", sleepEndTime: "070000", sleepTarget: 480 });
    const start = root.querySelector("[data-config-field=\"sleepStartTime\"]");
    const end = root.querySelector("[data-config-field=\"sleepEndTime\"]");

    assert.equal(start.type, "time");
    assert.equal(start.value, "22:00");
    assert.equal(end.value, "07:00");
});

test("a hora escolhida volta a sair no HHmmss que o relógio quer", () => {
    const root = section(sleepEntry, { enabled: true, sleepStartTime: "220000", sleepEndTime: "070000", sleepTarget: 480 });
    root.querySelector("[data-config-field=\"sleepStartTime\"]").value = "23:30";

    assert.equal(readConfigPayload(root).sleepStartTime, "233000");
    assert.equal(readConfigPayload(root).sleepEndTime, "070000");
});

test("o alerta de temperatura parte de 38,5 e aceita décimas", () => {
    const root = section(temperatureEntry);
    const value = root.querySelector("[data-config-field=\"RemindValue\"]");

    assert.equal(value.value, "38.5");
    assert.equal(value.step, "0.1");

    value.value = "37.8";
    assert.equal(readConfigPayload(root).RemindValue, 37.8);
});

test("o alerta de frequência cardíaca baixa não parte do limite da alta", () => {
    const root = section(lowHeartRateEntry);

    assert.equal(root.querySelector("[data-config-field=\"remindValue\"]").value, "");
});

test("o alerta de frequência cardíaca baixa não se envia sem limite", () => {
    const root = section(lowHeartRateEntry);

    assert.throws(() => readConfigPayload(root), /limite/i);
});
