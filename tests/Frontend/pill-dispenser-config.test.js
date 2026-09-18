import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

const alarms = CONFIG_INPUTS.pillDispenserAlarms;
const entry = (fields, overrides = {}) => ({ fields, ...overrides });

test("os nove alarmes são desenhados, ocupados ou não", () => {
    const html = alarms.render(entry(["plans"]), {
        plans: [{ hour: 8, minute: 30, enabled: true }],
    });

    // Nove linhas: o aparelho tem nove slots fixos e mostra-os todos, senão não há como
    // ligar o décimo — que não existe — nem desligar um que ficou de um plano anterior.
    assert.equal((html.match(/data-alarm-slot/g) || []).length, 9);
    assert.match(html, /value="8"/);
    assert.match(html, /value="30"/);
});

test("o plano parte vazio sem rebentar", () => {
    const html = alarms.render(entry(["plans"]), {});

    assert.equal((html.match(/data-alarm-slot/g) || []).length, 9);
    assert.deepEqual(alarms.defaults(entry(["plans"])), { plans: [] });
});

test("o som e o fuso trazem os campos que o aparelho precisa", () => {
    const sound = CONFIG_INPUTS.pillDispenserSound.render(
        entry(["volume", "ringtone"]),
        { volume: 3, ringtone: 1 },
    );
    assert.match(sound, /data-config-field="volume"/);
    assert.match(sound, /data-config-field="ringtone"/);

    const region = CONFIG_INPUTS.pillDispenserRegion.render(
        entry(["language", "timezoneMinutes"]),
        { language: 0, timezoneMinutes: 60 },
    );
    assert.match(region, /data-config-field="timezoneMinutes"/);
    assert.match(region, /value="60"/);
});

test("o não incomodar desenha a janela inteira", () => {
    const html = CONFIG_INPUTS.pillDispenserQuietHours.render(
        entry(["enabled", "startHour", "startMinute", "endHour", "endMinute"]),
        { enabled: true, startHour: 22, startMinute: 0, endHour: 7, endMinute: 30 },
    );

    for (const f of ["startHour", "startMinute", "endHour", "endMinute"]) {
        assert.match(html, new RegExp(`data-config-field="${f}"`));
    }
});

test("o modo de dispensa tem os dois interruptores separados", () => {
    const html = CONFIG_INPUTS.pillDispenserDispenseMode.render(
        entry(["earlyRetrieval", "childLock"]),
        { earlyRetrieval: true, childLock: false },
    );

    assert.match(html, /data-config-field="earlyRetrieval"/);
    assert.match(html, /data-config-field="childLock"/);
});

test("todos os campos do dispensador declaram as quatro faces", () => {
    for (const name of [
        "pillDispenserAlarms",
        "pillDispenserSound",
        "pillDispenserQuietHours",
        "pillDispenserRegion",
        "pillDispenserDispenseMode",
    ]) {
        const input = CONFIG_INPUTS[name];
        assert.ok(input, `${name} não está registado`);
        assert.equal(typeof input.render, "function", `${name}.render`);
        assert.equal(typeof input.read, "function", `${name}.read`);
        assert.equal(typeof input.defaults, "function", `${name}.defaults`);
    }
});
