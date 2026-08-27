import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
    defaultConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/**
 * A ida e volta do payload de configuração.
 *
 * Estes payloads são escritos em dispositivos reais, e por isso o que interessa é que o que o
 * formulário desenha seja exactamente o que o leitor devolve. Descrevem o comportamento de
 * hoje e não um ideal, para haver contra o que verificar as peças em que o código se divida.
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

test("wonlex switchState is presented and read back as enabled", () => {
    // O campo nativo é renomeado só neste protocolo: o leitor tem de concordar com quem
    // desenha, senão o payload perde o valor em silêncio.
    const entry = { input: "toggle", key: "x", fields: ["switchState"] };

    assert.deepEqual(
        roundTrip(entry, { switchState: true }, { protocol: "wonlex-json" }),
        { enabled: true },
    );
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
    assert.deepEqual(roundTrip({ input: "resetAction", key: "reset" }, {}), {});
    assert.deepEqual(roundTrip({ input: "requestAction", key: "ask" }, {}), {});
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
    const payload = roundTrip({ input: "not-a-real-input", key: "weird" }, { a: 1 });

    assert.equal(typeof payload, "object");
});

test("defaults are readable payloads for every input type the renderer knows", () => {
    // Um valor por omissão que o leitor não consiga devolver enviava a coisa errada à
    // primeira gravação de uma secção que ninguém tocou.
    for (const input of ["toggle", "number", "text", "intervalToggle", "pushMessage"]) {
        const entry = { input, key: input, fields: ["enabled"] };
        const defaults = defaultConfigPayload(entry, "");

        assert.equal(typeof defaults, "object", `${input} default should be an object`);
        assert.doesNotThrow(() => roundTrip(entry, defaults), `${input} default should read back`);
    }
});

test("a list keeps its numbers and honours the entry limit", () => {
    const entry = { input: "list", key: "numbers", limit: 2 };

    assert.deepEqual(
        roundTrip(entry, { numbers: ["912345678", "913333333", "914444444"] }),
        { numbers: ["+351912345678", "+351913333333"] },
    );
});

test("SOS contacts reject duplicates rather than silently collapsing them", () => {
    // Dois números iguais pareciam aceites mas deixavam um slot sem uso.
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
        { input: "contacts", key: "phonebook" },
        { contacts: [{ name: "Ana", phone: "912345678" }] },
    );

    assert.deepEqual(payload, { contacts: [{ name: "Ana", phone: "+351912345678" }] });
});

test("a contact missing its phone is rejected rather than half-saved", () => {
    assert.throws(
        () => roundTrip(
            { input: "contacts", key: "phonebook" },
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
