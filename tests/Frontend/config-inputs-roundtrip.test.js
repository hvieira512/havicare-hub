import test from "node:test";
import assert from "node:assert/strict";

// Must come before the dashboard modules: they touch window while loading.
import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/config.js";
import {configSection} from "./support/dom.js";

/**
 * Round trips for the configuration inputs the original characterisation file
 * left uncovered. What the form renders has to be what the reader gives back,
 * because that payload is what gets written to the device.
 *
 * Where the reader deliberately renames a field, the test says so: the
 * dashboard speaks the generic dialect and the hub maps it to the protocol's
 * native names.
 */
const roundTrip = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

const entryFor = (input, fields = [], extra = {}) => ({input, key: input, fields, ...extra});

test("phone survives the round trip", () => {
    assert.deepEqual(
        roundTrip(entryFor("phone", ["phone"]), {phone: "+351911111111"}),
        {phone: "+351911111111"},
    );
});

test("fall sensitivity survives the round trip", () => {
    assert.deepEqual(roundTrip(entryFor("fallSensitivity"), {sensitivity: 2}), {sensitivity: 2});
});

test("fall sensitivity levels keep the scale alongside the level", () => {
    assert.deepEqual(
        roundTrip(entryFor("fallSensitivityLevels"), {sensitivity: 4, levels: 8}),
        {sensitivity: 4, levels: 8},
    );
});

test("a stored sensitivity scale the firmware cannot use is offered as the default one", () => {
    // The form only offers 6 and 8, so a stored 7 renders as 8 rather than
    // presenting a scale that would be rejected on save.
    assert.deepEqual(
        roundTrip(entryFor("fallSensitivityLevels"), {sensitivity: 4, levels: 7}),
        {sensitivity: 4, levels: 8},
    );
});

test("hour interval toggle survives the round trip", () => {
    assert.deepEqual(
        roundTrip(entryFor("intervalHoursToggle"), {enabled: true, intervalHours: 3}),
        {enabled: true, intervalHours: 3},
    );
});

test("working mode and sound profile survive the round trip", () => {
    assert.deepEqual(roundTrip(entryFor("workingMode"), {mode: 3}), {mode: 3});
    assert.deepEqual(roundTrip(entryFor("soundProfile"), {mode: 2}), {mode: 2});
});

test("blood pressure keeps both readings", () => {
    assert.deepEqual(
        roundTrip(entryFor("bloodPressure"), {systolic: 118, diastolic: 76}),
        {systolic: 118, diastolic: 76},
    );
});

test("the wonlex blood pressure alert keeps both thresholds", () => {
    // The renderer offers hpWarn and LPWarn, the Wonlex definition declares
    // them, and WonlexPayloadBuilder requires them. A reader that returns
    // anything else means the thresholds the user typed never reach the device.
    assert.deepEqual(
        roundTrip(entryFor("wonlexBloodPressureWarning", ["switchState", "hpWarn", "LPWarn"]), {
            switchState: true,
            hpWarn: 135,
            LPWarn: 90,
        }),
        {enabled: true, hpWarn: 135, LPWarn: 90},
    );
});

test("language and timezone survive the round trip as a preset pair", () => {
    assert.deepEqual(
        roundTrip(entryFor("languageTimezone"), {language: 3, timeZone: "1"}),
        {language: 3, timeZone: "1"},
    );
});

test("a language and timezone combination with no preset falls back to the first one", () => {
    // Deliberate: the form only offers the presets the firmware supports.
    assert.deepEqual(
        roundTrip(entryFor("languageTimezone"), {language: 2, timeZone: "9"}),
        {language: 0, timeZone: "0"},
    );
});

test("dual toggle keeps both switches", () => {
    assert.deepEqual(
        roundTrip(entryFor("dualToggle"), {enabled: true, callCenterOnFall: true}),
        {enabled: true, callCenterOnFall: true},
    );
    assert.deepEqual(
        roundTrip(entryFor("dualToggle"), {enabled: false, callCenterOnFall: false}),
        {enabled: false, callCenterOnFall: false},
    );
});

test("time ranges survive the round trip, one range and several", () => {
    assert.deepEqual(roundTrip(entryFor("timeRange"), {range: "21:10-07:30"}), {range: "21:10-07:30"});
    assert.deepEqual(
        roundTrip(entryFor("timeRanges", [], {limit: 3}), {ranges: ["08:10-09:30", "12:00-13:00"]}),
        {ranges: ["08:10-09:30", "12:00-13:00"]},
    );
});

test("wonlex sleep settings survive, with switchState read back as enabled", () => {
    assert.deepEqual(
        roundTrip(entryFor("wonlexSleepSettings"), {
            switchState: true,
            sleepStartTime: "220000",
            sleepEndTime: "100000",
            sleepTarget: 480,
        }),
        {enabled: true, sleepStartTime: "220000", sleepEndTime: "100000", sleepTarget: 480},
    );
});

test("wonlex reminder threshold survives, with switchState read back as enabled", () => {
    assert.deepEqual(
        roundTrip(entryFor("wonlexReminderThreshold", ["switchState", "reminderValue"]), {
            switchState: true,
            reminderValue: 90,
        }),
        {enabled: true, reminderValue: 90},
    );
});

test("wonlex heart rate range survives, with both switches read back as enabled flags", () => {
    assert.deepEqual(
        roundTrip(entryFor("wonlexHeartRateRange"), {
            switchState: true,
            remindValue: 120,
            exerciseSwitchState: true,
            exerciseHRMin: 100,
            exerciseHRMax: 140,
            exerciseRemindValue: 140,
        }),
        {
            enabled: true,
            remindValue: 120,
            exerciseEnabled: true,
            exerciseHRMin: 100,
            exerciseHRMax: 140,
            exerciseRemindValue: 140,
        },
    );
});

test("whitelist_enabled survives the round trip", () => {
    assert.deepEqual(roundTrip(entryFor("whitelist_enabled"), {enabled: true}), {enabled: true});
    assert.deepEqual(roundTrip(entryFor("whitelist_enabled"), {enabled: false}), {enabled: false});
});

test("sos contacts are read back as a plain list of numbers", () => {
    assert.deepEqual(
        roundTrip(entryFor("sos_contacts", [], {limit: 3}), {numbers: ["+351911111111"]}, {limit: 3}),
        ["+351911111111"],
    );
});

test("the call whitelist is a list of numbers, and named contacts on vivistar", () => {
    const numbers = {numbers: ["+351911111111"]};
    const contacts = {contacts: [{name: "Ana", phone: "+351911111111"}]};

    assert.deepEqual(
        roundTrip(entryFor("call_whitelist", [], {limit: 5}), numbers, {limit: 5}),
        ["+351911111111"],
    );
    assert.deepEqual(
        roundTrip(entryFor("call_whitelist", [], {limit: 5}), contacts, {limit: 5, protocol: "vivistar-iw"}),
        contacts,
    );
});

test("the phonebook keeps names with its numbers", () => {
    const contacts = {contacts: [{name: "Ana", phone: "+351911111111"}]};

    assert.deepEqual(roundTrip(entryFor("phonebook", [], {limit: 5}), contacts, {limit: 5}), contacts);
});

test("alarm clock items survive the round trip", () => {
    const items = {items: [{time: "08:30", enabled: true, recurrence: {kind: "daily"}}]};

    assert.deepEqual(roundTrip(entryFor("alarm_clock", [], {limit: 3}), items, {limit: 3}), items);
});

test("take pills keeps its reminders and drops the mime type of unchanged audio", () => {
    // voiceMimeType only travels with newly recorded audio, so the reader has
    // no reason to send it back for an unchanged plan.
    assert.deepEqual(
        roundTrip(
            entryFor("takePills", [], {limit: 3}),
            {
                reminderSettings: [{time: "08:00", enabled: true, frequency: 1, custom: ""}],
                number: 1,
                reminderText: "Tomar comprimido",
                voiceData: "",
                voiceMimeType: "audio/webm",
            },
            {limit: 3},
        ),
        {
            reminderSettings: [{time: "08:00", enabled: true, frequency: 1, custom: ""}],
            number: 1,
            reminderText: "Tomar comprimido",
            voiceData: "",
        },
    );
});
