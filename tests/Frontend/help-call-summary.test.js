import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o nome de uma capacidade vem do catálogo, e esse
// caminho passa pelo `api/http.js`, que toca em `window` ao carregar.
import "./support/browser-env.js";
import { helpCallSummaryCard } from "../../src/Dashboard/dashboard/devices/event-summary-cards.js";

const call = (pressType, occurredAt) => ({
    type: "help_call",
    occurredAt,
    data: { pressType, triggerCount: 10, presses: 1 },
});

const W6B_MODES = ["single", "double", "long"];

test("a device that never called for help gets no card", () => {
    assert.equal(helpCallSummaryCard([], W6B_MODES), "");
    assert.equal(
        helpCallSummaryCard([{ type: "battery", data: { percent: 90 } }], W6B_MODES),
        "",
    );
});

test("a protocol that declares no press modes gets no card", () => {
    // O W812 da Voerka chama por botão de comando e não por modo de toque: o cartão dos
    // toques desenhava três colunas a dizer "nunca" ao lado dos eventos que mostram a
    // chamada verdadeira. Sem modos declarados não há resumo por modo.
    const ncsCall = {
        type: "help_call",
        occurredAt: "2026-09-04T11:45:16Z",
        data: { pagerId: "348319" },
    };

    assert.equal(helpCallSummaryCard([ncsCall]), "");
    assert.equal(helpCallSummaryCard([ncsCall], []), "");
});

test("every press mode is listed, including ones never used", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")], W6B_MODES);

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

    // E a W6B é o contrário.
    const w6b = helpCallSummaryCard(
        [call("long", "2026-08-10T12:26:49Z")],
        ["single", "double", "long"],
    );

    assert.match(w6b, /Toque longo/);
    assert.doesNotMatch(w6b, /Toque triplo/);
});

test("only the most recent call of each mode is shown", () => {
    const html = helpCallSummaryCard([
        call("single", "2026-08-10T12:20:00Z"),
        call("single", "2026-08-10T12:26:49Z"),
        call("double", "2026-08-10T12:26:18Z"),
    ], W6B_MODES);

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
        helpCallSummaryCard([newest, older], W6B_MODES),
        helpCallSummaryCard([older, newest], W6B_MODES),
    );
});

test("unknown press types are ignored rather than rendered raw", () => {
    const html = helpCallSummaryCard([call("inactivity", "2026-08-10T12:26:49Z")], W6B_MODES);

    // A inactividade é descodificada mas nunca dá alarme, de propósito.
    assert.doesNotMatch(html, /inactivity/);
    assert.equal(html.match(/help-call-never/g).length, 3);
});

test("the card states no alarm status, only when each press last happened", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")], W6B_MODES);

    // O dispositivo não nos consegue dizer que uma chamada foi cancelada, e por isso o cartão
    // não pode sugerir um alarme activo nem limpo.
    assert.match(html, /Últimas chamadas de ajuda/);
    assert.doesNotMatch(html, /ativ[oa]|em curso|cancelad/i);
});

test("the modes lay out as three columns that stack on small viewports", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")], W6B_MODES);

    assert.match(html, /class="row g-2"/);
    assert.equal(html.match(/class="col-12 col-md-4"/g).length, 3);
});

test("each mode carries its own icon", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")], W6B_MODES);

    for (const icon of ["fa-1", "fa-2", "fa-stopwatch"]) {
        assert.match(html, new RegExp(`fa-solid ${icon} `));
    }
});

test("a mode that has fired carries a tooltip with the exact timestamp", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")], W6B_MODES);

    assert.match(html, /data-bs-toggle="tooltip"/);
    // A hora desenhada depende da locale, e por isso só se afirma a presença dela.
    assert.match(html, /data-bs-title="[^"]+"/);
    // Alcançável pelo teclado: só com o rato, ficava escondido de alguns utilizadores.
    assert.match(html, /tabindex="0"/);
});

test("a mode that never fired has no tooltip, having no timestamp to show", () => {
    const html = helpCallSummaryCard([call("single", "2026-08-10T12:26:49Z")], W6B_MODES);

    // Uma chamada, e por isso exactamente uma das três colunas responde ao rato.
    assert.equal(html.match(/data-bs-toggle="tooltip"/g).length, 1);
    assert.equal(html.match(/data-occurred-at=/g).length, 1);
});

test("payload rows wrapped by the store are unwrapped", () => {
    const html = helpCallSummaryCard([
        { payload: call("double", "2026-08-10T12:26:18Z") },
    ], W6B_MODES);

    assert.match(html, /Toque duplo/);
    assert.equal(html.match(/help-call-never/g).length, 2);
});
