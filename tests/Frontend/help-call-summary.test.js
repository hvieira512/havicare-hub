import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o nome de uma capacidade vem do catálogo, e esse
// caminho passa pelo `api/http.js`, que toca em `window` ao carregar.
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
    // Dois modos nunca dispararam, e dizê-lo é mais útil do que escondê-los.
    assert.equal(html.match(/help-call-never/g).length, 2);
});

test("the modes drawn are the ones the protocol declares", () => {
    // Uma W6 não tem toque longo, e dizer "toque longo -- nunca" leria como um modo por
    // usar em vez de um que a pulseira não sabe fazer.
    const w6 = helpCallSummaryCard(
        [call("triple", "2026-08-10T12:26:49Z")],
        ["single", "double", "triple"],
    );

    assert.match(w6, /Toque triplo/);
    assert.doesNotMatch(w6, /Toque longo/);
    assert.match(w6, /fa-solid fa-3 /);
    assert.equal(w6.match(/help-call-never/g).length, 2);

    // E a W6R é o contrário.
    const w6r = helpCallSummaryCard(
        [call("long", "2026-08-10T12:26:49Z")],
        ["single", "double", "long"],
    );

    assert.match(w6r, /Toque longo/);
    assert.doesNotMatch(w6r, /Toque triplo/);
});

test("only the most recent call of each mode is shown", () => {
    const html = helpCallSummaryCard([
        call("single", "2026-08-10T12:20:00Z"),
        call("single", "2026-08-10T12:26:49Z"),
        call("double", "2026-08-10T12:26:18Z"),
    ]);

    // Afirmado sobre a hora em ISO e não sobre a hora local desenhada, que depende do fuso
    // da máquina.
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

    // A inactividade é descodificada mas nunca dá alarme, de propósito.
    assert.doesNotMatch(html, /inactivity/);
    assert.equal(html.match(/help-call-never/g).length, 3);
});

test("the card states no alarm status, only when each press last happened", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    // O dispositivo não nos consegue dizer que uma chamada foi cancelada, e por isso o cartão
    // não pode sugerir um alarme activo nem limpo.
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
    // A hora desenhada depende da locale, e por isso só se afirma a presença dela.
    assert.match(html, /data-bs-title="[^"]+"/);
    // Alcançável pelo teclado: só com o rato, ficava escondido de alguns utilizadores.
    assert.match(html, /tabindex="0"/);
});

test("a mode that never fired has no tooltip, having no timestamp to show", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")]);

    // Uma chamada, e por isso exactamente uma das três colunas responde ao rato.
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
