import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o nome de uma capacidade vem do catalogo, e
// esse caminho passa pelo api/http.js, que toca em window ao carregar.
import "./support/browser-env.js";
import {helpCallSummaryCard} from "../../src/Dashboard/dashboard/telemetry-cards.js";

const call = (pressType, occurredAt) => ({
    type: "help_call",
    occurredAt,
    data: {pressType, triggerCount: 10, presses: 1},
});

test("a device that never called for help gets no card", () => {
    assert.equal(helpCallSummaryCard([]), "");
    assert.equal(helpCallSummaryCard([{type: "battery", data: {percent: 90}}]), "");
});

test("every press mode is listed, including ones never used", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    assert.match(html, /Toque simples/);
    assert.match(html, /Toque duplo/);
    assert.match(html, /Toque longo/);
    // Two modes have never fired, and saying so is more useful than hiding them.
    assert.equal(html.match(/help-call-never/g).length, 2);
});

test("only the most recent call of each mode is shown", () => {
    const html = helpCallSummaryCard([
        call("single", "2026-08-10T12:20:00Z"),
        call("single", "2026-08-10T12:26:49Z"),
        call("double", "2026-08-10T12:26:18Z"),
    ]);

    // Asserted on the ISO timestamp rather than the rendered local time, which
    // depends on the machine's timezone.
    assert.match(html, /data-occurred-at="2026-08-10T12:26:49Z"/);
    assert.doesNotMatch(html, /2026-08-10T12:20:00Z/);
    assert.equal(html.match(/help-call-never/g).length, 1);
});

test("order of the events does not matter", () => {
    const newest = call("long", "2026-08-10T12:30:00Z");
    const older = call("long", "2026-08-10T09:00:00Z");

    assert.equal(
        helpCallSummaryCard([newest, older]),
        helpCallSummaryCard([older, newest]),
    );
});

test("unknown press types are ignored rather than rendered raw", () => {
    const html = helpCallSummaryCard([call("inactivity", "2026-08-10T12:26:49Z")]);

    // Inactivity is decoded but deliberately never alarms.
    assert.doesNotMatch(html, /inactivity/);
    assert.equal(html.match(/help-call-never/g).length, 3);
});

test("the card states no alarm status, only when each press last happened", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    // The device cannot tell us a call was cancelled, so the card must not
    // imply an active or cleared alarm.
    assert.match(html, /Últimas chamadas de ajuda/);
    assert.doesNotMatch(html, /ativ[oa]|em curso|cancelad/i);
});

test("the modes lay out as three columns that stack on small viewports", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    assert.match(html, /class="row g-2"/);
    assert.equal(html.match(/class="col-12 col-md-4"/g).length, 3);
});

test("each mode carries its own icon", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    for (const icon of ["fa-1", "fa-2", "fa-stopwatch"]) {
        assert.match(html, new RegExp(`fa-solid ${icon} `));
    }
});

test("a mode that has fired carries a tooltip with the exact timestamp", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    assert.match(html, /data-bs-toggle="tooltip"/);
    // The rendered time is locale dependent, so only its presence is asserted.
    assert.match(html, /data-bs-title="[^"]+"/);
    // Reachable by keyboard, since hover alone would hide it from some users.
    assert.match(html, /tabindex="0"/);
});

test("a mode that never fired has no tooltip, having no timestamp to show", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    // One call, so exactly one of the three columns is hoverable.
    assert.equal(html.match(/data-bs-toggle="tooltip"/g).length, 1);
    assert.equal(html.match(/data-occurred-at=/g).length, 1);
});

test("payload rows wrapped by the store are unwrapped", () => {
    const html = helpCallSummaryCard([
        {payload: call("double", "2026-08-10T12:26:18Z")},
    ]);

    assert.match(html, /Toque duplo/);
    assert.equal(html.match(/help-call-never/g).length, 2);
});
