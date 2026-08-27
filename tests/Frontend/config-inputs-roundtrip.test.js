import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/**
 * A ida e volta dos tipos de campo de configuração que o outro ficheiro não cobre. O que o
 * formulário desenha tem de ser o que o leitor devolve, porque é esse payload que vai escrito
 * para o dispositivo.
 *
 * Onde o leitor renomeia um campo de propósito, o teste di-lo: a dashboard fala o dialecto
 * genérico e o hub mapeia-o nos nomes nativos do protocolo.
 */
const roundTrip = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

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
    // Quem desenha oferece o `hpWarn` e o `LPWarn`, a definição da Wonlex declara-os, e o
    // `WonlexPayloadBuilder` exige-os. Um leitor que devolva outra coisa faz com que os
    // limiares escritos nunca cheguem ao dispositivo.
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

test("whitelist_enabled survives the round trip", () => {
    assert.deepEqual(roundTrip(entryFor("whitelist_enabled"), { enabled: true }), { enabled: true });
    assert.deepEqual(roundTrip(entryFor("whitelist_enabled"), { enabled: false }), { enabled: false });
});

test("sos contacts are read back as a plain list of numbers", () => {
    assert.deepEqual(
        roundTrip(entryFor("sos_contacts", [], { limit: 3 }), { numbers: ["+351911111111"] }, { limit: 3 }),
        ["+351911111111"],
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

test("the phonebook keeps names with its numbers", () => {
    const contacts = { contacts: [{ name: "Ana", phone: "+351911111111" }] };

    assert.deepEqual(roundTrip(entryFor("phonebook", [], { limit: 5 }), contacts, { limit: 5 }), contacts);
});

test("alarm clock items survive the round trip", () => {
    const items = { items: [{ time: "08:30", enabled: true, recurrence: { kind: "daily" } }] };

    assert.deepEqual(roundTrip(entryFor("alarm_clock", [], { limit: 3 }), items, { limit: 3 }), items);
});

test("take pills keeps its reminders and drops the mime type of unchanged audio", () => {
    // O `voiceMimeType` só viaja com áudio acabado de gravar, e por isso o leitor não tem
    // razão para o devolver num plano que não mudou.
    assert.deepEqual(
        roundTrip(
            entryFor("takePills", [], { limit: 3 }),
            {
                reminderSettings: [{ time: "08:00", enabled: true, frequency: 1, custom: "" }],
                number: 1,
                reminderText: "Tomar comprimido",
                voiceData: "",
                voiceMimeType: "audio/webm",
            },
            { limit: 3 },
        ),
        {
            reminderSettings: [{ time: "08:00", enabled: true, frequency: 1, custom: "" }],
            number: 1,
            reminderText: "Tomar comprimido",
            voiceData: "",
        },
    );
});
