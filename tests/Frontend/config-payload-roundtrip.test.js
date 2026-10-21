import test from "node:test";
import assert from "node:assert/strict";

import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/**
 * Estes payloads são escritos em dispositivos reais: o que o formulário desenha tem de ser
 * exactamente o que o leitor devolve. Descrevem o comportamento de hoje, e não um ideal.
 */
const roundTrip = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

test("toggle survives the round trip in both states", () => {
    const entry = { input: "toggle", key: "sos", fields: ["enabled"] };

    assert.deepEqual(roundTrip(entry, { enabled: true }), { enabled: true });
    assert.deepEqual(roundTrip(entry, { enabled: false }), { enabled: false });
});

test("toggle uses the entry's own field name", () => {
    const entry = { input: "toggle", key: "screen", fields: ["screenOn"] };

    assert.deepEqual(roundTrip(entry, { screenOn: true }), { screenOn: true });
});

test("number survives the round trip", () => {
    const entry = { input: "number", key: "interval", fields: ["interval"] };

    assert.deepEqual(roundTrip(entry, { interval: 42 }), { interval: 42 });
    assert.deepEqual(roundTrip(entry, { interval: 0 }), { interval: 0 });
});

test("text survives the round trip, including characters that need escaping", () => {
    const entry = { input: "text", key: "label", fields: ["value"] };

    assert.deepEqual(roundTrip(entry, { value: "a \"quoted\" <b>" }), { value: "a \"quoted\" <b>" });
});

test("an empty text field reads back as an empty string, not undefined", () => {
    const entry = { input: "text", key: "label", fields: ["value"] };

    assert.deepEqual(roundTrip(entry, {}), { value: "" });
});

test("action inputs carry no payload", () => {
    assert.deepEqual(roundTrip({ input: "action", key: "reset" }, {}), {});
});

test("interval toggle keeps both of its fields together", () => {
    const entry = { input: "intervalToggle", key: "hr", fields: ["enabled", "intervalMinutes"] };

    assert.deepEqual(
        roundTrip(entry, { enabled: true, intervalMinutes: 15 }),
        { enabled: true, intervalMinutes: 15 },
    );
});

test("push message reads its fixed field name", () => {
    assert.deepEqual(
        roundTrip({ input: "pushMessage", key: "msg" }, { message: "olá" }),
        { message: "olá" },
    );
});

test("a national phone number comes back in E.164", () => {
    // O campo de telefone aplica o indicativo por omissão de passagem, e por isso o que o
    // dispositivo recebe não é o que quem chamou passou.
    assert.deepEqual(
        roundTrip({ input: "makeCall", key: "call" }, { phone: "912345678" }),
        { phone: "+351912345678" },
    );
});

test("a phone number that already has a country code keeps it", () => {
    assert.deepEqual(
        roundTrip({ input: "makeCall", key: "call" }, { phone: "+447700900123" }),
        { phone: "+447700900123" },
    );
});

test("a number with the wrong digit count for its country is rejected, not truncated", () => {
    // A leitura estoira, para nunca sair um número mal formado para um dispositivo.
    assert.throws(
        () => roundTrip({ input: "makeCall", key: "call" }, { phone: "+44770090012345" }),
        /Reino Unido/,
    );
});

test("an unknown input type falls back to the json reader rather than throwing", () => {
    assert.deepEqual(roundTrip({ input: "not-a-real-input", key: "weird" }, { a: 1 }), { a: 1 });
});

test("a window is split into two time fields and joined back", () => {
    // As horas são desenhadas separadas para o navegador não deixar escrever uma que não
    // existe, mas o que vai para o aparelho é o par junto.
    const entry = { input: "windowToggle", key: "blood_oxygen_window", fields: ["enabled", "range"] };

    assert.deepEqual(
        roundTrip(entry, { enabled: true, range: "22:00-08:00" }),
        { enabled: true, range: "22:00-08:00" },
    );
});

test("heart rate thresholds survive the round trip", () => {
    const entry = {
        input: "heartRateThresholds",
        key: "heart_rate_alert",
        fields: ["enabled", "maxBpm", "minBpm"],
    };

    assert.deepEqual(
        roundTrip(entry, { enabled: true, maxBpm: 150, minBpm: 50 }),
        { enabled: true, maxBpm: 150, minBpm: 50 },
    );
});

test("heart rate thresholds refuse a minimum above the maximum", () => {
    const entry = {
        input: "heartRateThresholds",
        key: "heart_rate_alert",
        fields: ["enabled", "maxBpm", "minBpm"],
    };

    assert.throws(() => roundTrip(entry, { enabled: true, maxBpm: 60, minBpm: 90 }), /mínimo/);
});

test("a decimal weight keeps its decimals", () => {
    const entry = {
        input: "personalInfo",
        key: "personal_info",
        fields: ["heightCm", "weightKg", "age", "sex", "stepGoal", "sleepGoalMinutes"],
    };

    assert.equal(roundTrip(entry, { weightKg: 65.5 }).weightKg, 65.5);
});

test("personal information survives the round trip", () => {
    const entry = {
        input: "personalInfo",
        key: "personal_info",
        fields: ["heightCm", "weightKg", "age", "sex", "stepGoal", "sleepGoalMinutes"],
    };
    const desired = {
        heightCm: 181, weightKg: 78, age: 29, sex: "male", stepGoal: 10000, sleepGoalMinutes: 450,
    };

    assert.deepEqual(roundTrip(entry, desired), desired);
});

test("SOS contacts reject duplicates rather than silently collapsing them", () => {
    // Dois números iguais deixariam um slot sem uso.
    assert.throws(
        () => roundTrip(
            { input: "sos_contacts", key: "sos", limit: 3 },
            { numbers: ["912345678", "912345678"] },
        ),
        /Contactos SOS/,
    );
});

test("contacts survive the round trip as name and phone pairs", () => {
    const payload = roundTrip(
        { input: "phonebook", key: "phonebook" },
        { contacts: [{ name: "Ana", phone: "912345678" }] },
    );

    assert.deepEqual(payload, { contacts: [{ name: "Ana", phone: "+351912345678" }] });
});

test("a contact missing its phone is rejected rather than half-saved", () => {
    assert.throws(
        () => roundTrip(
            { input: "phonebook", key: "phonebook" },
            { contacts: [{ name: "Ana", phone: "" }] },
        ),
        /obrigat/,
    );
});

test("alarm clock entries survive the round trip", () => {
    const desired = [
        { time: "08:40", enabled: true, recurrence: { kind: "daily" } },
        { time: "21:15", enabled: false, recurrence: { kind: "once" } },
    ];

    const payload = roundTrip({ input: "alarm_clock", key: "alarm_clock", limit: 3 }, desired);

    assert.deepEqual(payload, { items: desired });
});

test("a custom-recurrence alarm keeps the days it selected", () => {
    const desired = [
        { time: "07:00", enabled: true, recurrence: { kind: "custom", days: [1, 3, 5] } },
    ];

    const { items } = roundTrip({ input: "alarm_clock", key: "alarm_clock", limit: 3 }, desired);

    assert.equal(items[0].recurrence.kind, "custom");
    assert.deepEqual(items[0].recurrence.days, [1, 3, 5]);
});

test("an untouched default alarm row is not saved as a blank alarm", () => {
    // Quem desenha emite sempre uma linha para haver de onde clonar, mas uma linha sem hora
    // não pode chegar ao dispositivo.
    assert.deepEqual(roundTrip({ input: "alarm_clock", key: "alarm_clock", limit: 3 }, []), { items: [] });
});

test("the 4P Touch alarm list survives the round trip", () => {
    const desired = { alarms: [{ time: "09:54", enabled: true, frequency: 2, custom: "" }] };

    const payload = roundTrip({ input: "alarms", key: "alarms", limit: 3 }, desired);

    assert.equal(payload.alarms.length, 1);
    assert.equal(payload.alarms[0].time, "09:54");
    assert.equal(payload.alarms[0].enabled, true);
});

const entryFor = (input, fields = [], extra = {}) => ({ input, key: input, fields, ...extra });

test("phone survives the round trip", () => {
    assert.deepEqual(
        roundTrip(entryFor("phone", ["phone"]), { phone: "+351911111111" }),
        { phone: "+351911111111" },
    );
});

test("fall sensitivity survives the round trip", () => {
    assert.deepEqual(roundTrip(entryFor("fallSensitivity"), { sensitivity: 2 }), { sensitivity: 2 });
});

test("fall sensitivity levels keep the scale alongside the level", () => {
    assert.deepEqual(
        roundTrip(entryFor("fallSensitivityLevels"), { sensitivity: 4, levels: 8 }),
        { sensitivity: 4, levels: 8 },
    );
});

test("a stored sensitivity scale the firmware cannot use is offered as the default one", () => {
    // O formulário só oferece 6 e 8, e por isso um 7 guardado desenha-se como 8 em vez de
    // apresentar uma escala que seria rejeitada ao gravar.
    assert.deepEqual(
        roundTrip(entryFor("fallSensitivityLevels"), { sensitivity: 4, levels: 7 }),
        { sensitivity: 4, levels: 8 },
    );
});

test("hour interval toggle survives the round trip", () => {
    assert.deepEqual(
        roundTrip(entryFor("intervalHoursToggle"), { enabled: true, intervalHours: 3 }),
        { enabled: true, intervalHours: 3 },
    );
});

test("working mode and sound profile survive the round trip", () => {
    assert.deepEqual(roundTrip(entryFor("workingMode"), { mode: 3 }), { mode: 3 });
    assert.deepEqual(roundTrip(entryFor("soundProfile"), { mode: 2 }), { mode: 2 });
});

test("blood pressure keeps both readings", () => {
    assert.deepEqual(
        roundTrip(entryFor("bloodPressure"), { systolic: 118, diastolic: 76 }),
        { systolic: 118, diastolic: 76 },
    );
});

test("the wonlex blood pressure alert keeps both thresholds", () => {
    // O `hpWarn` e o `LPWarn` são os que a definição da Wonlex declara e o
    // `WonlexPayloadBuilder` exige.
    assert.deepEqual(
        roundTrip(entryFor("wonlexBloodPressureWarning", ["switchState", "hpWarn", "LPWarn"]), {
            switchState: true,
            hpWarn: 135,
            LPWarn: 90,
        }),
        { enabled: true, hpWarn: 135, LPWarn: 90 },
    );
});

test("language and timezone survive the round trip as a preset pair", () => {
    assert.deepEqual(
        roundTrip(entryFor("languageTimezone"), { language: 3, timeZone: "1" }),
        { language: 3, timeZone: "1" },
    );
});

test("a language and timezone combination with no preset falls back to the first one", () => {
    // Deliberado: o formulário só oferece os presets que o firmware suporta.
    assert.deepEqual(
        roundTrip(entryFor("languageTimezone"), { language: 2, timeZone: "9" }),
        { language: 0, timeZone: "0" },
    );
});

test("dual toggle keeps both switches", () => {
    assert.deepEqual(
        roundTrip(entryFor("dualToggle"), { enabled: true, callCenterOnFall: true }),
        { enabled: true, callCenterOnFall: true },
    );
    assert.deepEqual(
        roundTrip(entryFor("dualToggle"), { enabled: false, callCenterOnFall: false }),
        { enabled: false, callCenterOnFall: false },
    );
});

test("time ranges survive the round trip, one range and several", () => {
    assert.deepEqual(roundTrip(entryFor("timeRange"), { range: "21:10-07:30" }), { range: "21:10-07:30" });
    assert.deepEqual(
        roundTrip(entryFor("timeRanges", [], { limit: 3 }), { ranges: ["08:10-09:30", "12:00-13:00"] }),
        { ranges: ["08:10-09:30", "12:00-13:00"] },
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
        { enabled: true, sleepStartTime: "220000", sleepEndTime: "100000", sleepTarget: 480 },
    );
});

test("wonlex reminder threshold survives, with switchState read back as enabled", () => {
    assert.deepEqual(
        roundTrip(entryFor("wonlexReminderThreshold", ["switchState", "reminderValue"]), {
            switchState: true,
            reminderValue: 90,
        }),
        { enabled: true, reminderValue: 90 },
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

test("the call whitelist is a list of numbers, and named contacts on vivistar", () => {
    const numbers = { numbers: ["+351911111111"] };
    const contacts = { contacts: [{ name: "Ana", phone: "+351911111111" }] };

    assert.deepEqual(
        roundTrip(entryFor("call_whitelist", [], { limit: 5 }), numbers, { limit: 5 }),
        ["+351911111111"],
    );
    assert.deepEqual(
        roundTrip(entryFor("call_whitelist", [], { limit: 5 }), contacts, { limit: 5, protocol: "vivistar-iw" }),
        contacts,
    );
});

test("take pills keeps its reminders and drops the mime type of unchanged audio", () => {
    // O `voiceMimeType` só viaja com áudio acabado de gravar, e por isso o leitor não tem
    // razão para o devolver num plano que não mudou.
    assert.deepEqual(
        roundTrip(
            entryFor("takePills", [], { limit: 3 }),
            {
                plans: [{ times: [{ time: "08:00", enabled: true, recurrence: { kind: "once" } }] }],
                reminderText: "Tomar comprimido",
                voiceData: "",
                voiceMimeType: "audio/webm",
            },
            { limit: 3 },
        ),
        {
            plans: [{ times: [{ time: "08:00", enabled: true, recurrence: { kind: "once" } }] }],
            reminderText: "Tomar comprimido",
            voiceData: "",
        },
    );
});
