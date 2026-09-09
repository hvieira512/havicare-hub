import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { notificationRow } from "../../src/Dashboard/dashboard/notifications.js";

/**
 * A cor dizia o contrário do risco.
 *
 * «Bloquear dispositivo» -- que escreve na denylist e cala o aparelho -- era um contorno
 * cinzento neutro, e «Eliminar notificação» -- que dispensa um item transitório -- era
 * vermelho. E registar o aparelho, que é o que se quer fazer na maioria dos casos, não tinha
 * botão nenhum: acontecia ao clicar no corpo da notificação, sem nada que o anunciasse.
 */
const notAuthorized = {
    id: 7,
    type: "device_not_authorized",
    imei: "351266770073676",
    protocol: "4ptouch",
    licenseId: 1001,
    occurrenceCount: 3,
    lastSeenAt: "2026-09-09T18:00:00Z",
};

const hubRestart = {
    id: 8,
    type: "hub_unclean_restart",
    reason: "o processo 58 terminou sem se desligar em condições",
    occurrenceCount: 1,
    lastSeenAt: "2026-09-09T15:02:00Z",
};

const button = (markup, action) =>
    markup.split("<button").find((chunk) => chunk.includes(action)) || "";

test("bloquear é o botão vermelho, e dispensar o neutro", () => {
    const markup = notificationRow(notAuthorized);

    assert.match(button(markup, "data-notification-block"), /btn-outline-danger/);
    assert.match(button(markup, "data-notification-dismiss"), /btn-outline-secondary/);
    assert.doesNotMatch(button(markup, "data-notification-dismiss"), /btn-outline-danger/);
});

test("registar o aparelho tem botão próprio", () => {
    const markup = notificationRow(notAuthorized);

    assert.match(markup, /data-notification-register="7"/);
    assert.match(button(markup, "data-notification-register"), /Registar/);
});

test("um aviso que não é de um aparelho só se dispensa", () => {
    const markup = notificationRow(hubRestart);

    assert.doesNotMatch(markup, /data-notification-block/);
    assert.doesNotMatch(markup, /data-notification-register/);
    assert.match(markup, /data-notification-dismiss="8"/);
});
