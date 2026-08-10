import test from "node:test";
import assert from "node:assert/strict";

// Must come before the dashboard modules: they touch window while loading.
import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
    defaultConfigPayload,
} from "../../src/Dashboard/dashboard/config.js";
import {configSection} from "./support/dom.js";

/**
 * Characterisation tests for the configuration payload round trip.
 *
 * These payloads are written to real devices, so what matters is that what the
 * form renders is exactly what the reader gives back. They describe today's
 * behaviour rather than an ideal, so that config.js can be split apart with
 * something to check the pieces against.
 */
const roundTrip = (entry, desired, meta = {}) =>
    readConfigPayload(configSection(renderConfigInputs, entry, desired, meta));

test("toggle survives the round trip in both states", () => {
    const entry = {input: "toggle", key: "sos", fields: ["enabled"]};

    assert.deepEqual(roundTrip(entry, {enabled: true}), {enabled: true});
    assert.deepEqual(roundTrip(entry, {enabled: false}), {enabled: false});
});

test("toggle uses the entry's own field name", () => {
    const entry = {input: "toggle", key: "screen", fields: ["screenOn"]};

    assert.deepEqual(roundTrip(entry, {screenOn: true}), {screenOn: true});
});

test("wonlex switchState is presented and read back as enabled", () => {
    // The native field is renamed for this protocol only; the reader has to
    // agree with the renderer or the payload silently loses the value.
    const entry = {input: "toggle", key: "x", fields: ["switchState"]};

    assert.deepEqual(
        roundTrip(entry, {switchState: true}, {protocol: "wonlex-json"}),
        {enabled: true},
    );
});

test("number survives the round trip", () => {
    const entry = {input: "number", key: "interval", fields: ["interval"]};

    assert.deepEqual(roundTrip(entry, {interval: 42}), {interval: 42});
    assert.deepEqual(roundTrip(entry, {interval: 0}), {interval: 0});
});

test("text survives the round trip, including characters that need escaping", () => {
    const entry = {input: "text", key: "label", fields: ["value"]};

    assert.deepEqual(roundTrip(entry, {value: 'a "quoted" <b>'}), {value: 'a "quoted" <b>'});
});

test("an empty text field reads back as an empty string, not undefined", () => {
    const entry = {input: "text", key: "label", fields: ["value"]};

    assert.deepEqual(roundTrip(entry, {}), {value: ""});
});

test("action inputs carry no payload", () => {
    assert.deepEqual(roundTrip({input: "resetAction", key: "reset"}, {}), {});
    assert.deepEqual(roundTrip({input: "requestAction", key: "ask"}, {}), {});
});

test("interval toggle keeps both of its fields together", () => {
    const entry = {input: "intervalToggle", key: "hr", fields: ["enabled", "intervalMinutes"]};

    assert.deepEqual(
        roundTrip(entry, {enabled: true, intervalMinutes: 15}),
        {enabled: true, intervalMinutes: 15},
    );
});

test("push message reads its fixed field name", () => {
    assert.deepEqual(
        roundTrip({input: "pushMessage", key: "msg"}, {message: "olá"}),
        {message: "olá"},
    );
});

test("a national phone number comes back in E.164", () => {
    // The phone control applies the default country code on the way through,
    // so what the device receives is not what the caller passed in.
    assert.deepEqual(
        roundTrip({input: "makeCall", key: "call"}, {phone: "912345678"}),
        {phone: "+351912345678"},
    );
});

test("a phone number that already has a country code keeps it", () => {
    assert.deepEqual(
        roundTrip({input: "makeCall", key: "call"}, {phone: "+447700900123"}),
        {phone: "+447700900123"},
    );
});

test("a number with the wrong digit count for its country is rejected, not truncated", () => {
    // Reading throws so the caller never sends a malformed number to a device.
    assert.throws(
        () => roundTrip({input: "makeCall", key: "call"}, {phone: "+44770090012345"}),
        /Reino Unido/,
    );
});

test("an unknown input type falls back to the json reader rather than throwing", () => {
    const payload = roundTrip({input: "not-a-real-input", key: "weird"}, {a: 1});

    assert.equal(typeof payload, "object");
});

test("defaults are readable payloads for every input type the renderer knows", () => {
    // A default that the reader cannot round trip would send the wrong thing
    // the first time a user saves a section they never touched.
    for (const input of ["toggle", "number", "text", "intervalToggle", "pushMessage"]) {
        const entry = {input, key: input, fields: ["enabled"]};
        const defaults = defaultConfigPayload(entry, "");

        assert.equal(typeof defaults, "object", `${input} default should be an object`);
        assert.doesNotThrow(() => roundTrip(entry, defaults), `${input} default should read back`);
    }
});

test("a list keeps its numbers and honours the entry limit", () => {
    const entry = {input: "list", key: "numbers", limit: 2};

    assert.deepEqual(
        roundTrip(entry, {numbers: ["912345678", "913333333", "914444444"]}),
        {numbers: ["+351912345678", "+351913333333"]},
    );
});

test("SOS contacts reject duplicates rather than silently collapsing them", () => {
    // Two identical numbers would look accepted but leave one slot unused.
    assert.throws(
        () => roundTrip(
            {input: "sos_contacts", key: "sos", limit: 3},
            {numbers: ["912345678", "912345678"]},
        ),
        /Contactos SOS/,
    );
});

test("contacts survive the round trip as name and phone pairs", () => {
    const payload = roundTrip(
        {input: "contacts", key: "phonebook"},
        {contacts: [{name: "Ana", phone: "912345678"}]},
    );

    assert.deepEqual(payload, {contacts: [{name: "Ana", phone: "+351912345678"}]});
});

test("a contact missing its phone is rejected rather than half-saved", () => {
    assert.throws(
        () => roundTrip(
            {input: "contacts", key: "phonebook"},
            {contacts: [{name: "Ana", phone: ""}]},
        ),
        /obrigat/,
    );
});
