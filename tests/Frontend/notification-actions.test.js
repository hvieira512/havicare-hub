import test from "node:test";
import assert from "node:assert/strict";

import { notificationRow as buildRow, notificationsPanel } from "../../src/Dashboard/dashboard/notifications.js";
import { asText } from "./support/dom.js";

const notificationRow = asText(buildRow);

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

test("o rodapé leva aos bloqueados, com ou sem notificações", () => {
    const empty = notificationsPanel([]);
    const filled = notificationsPanel([notAuthorized]);

    assert.match(empty, /data-notification-denylist/);
    assert.match(empty, /Ver bloqueados/);
    assert.match(filled, /data-notification-denylist/);
});
