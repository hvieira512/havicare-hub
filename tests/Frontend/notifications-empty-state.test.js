import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { notificationsPanel } from "../../src/Dashboard/dashboard/notifications.js";

/**
 * «Sem notificações.» não diz o que apareceria ali nem onde ver o que se bloqueou. O estado
 * vazio nomeia as duas coisas que o sino recebe, e o rodapé leva ao sítio delas.
 */
test("o vazio diz o que vai aparecer ali", () => {
    const markup = notificationsPanel([]);

    assert.match(markup, /não autorizados/);
    assert.match(markup, /reinícios do hub/);
});

test("o rodapé leva aos bloqueados, com ou sem notificações", () => {
    const empty = notificationsPanel([]);
    const filled = notificationsPanel([
        {
            id: 7,
            type: "device_not_authorized",
            imei: "351266770073676",
            occurrenceCount: 1,
            lastSeenAt: "2026-09-29T18:00:00Z",
        },
    ]);

    assert.match(empty, /data-notification-denylist/);
    assert.match(empty, /Ver bloqueados/);
    assert.match(filled, /data-notification-denylist/);
});

test("com notificações não há estado vazio", () => {
    const markup = notificationsPanel([
        {
            id: 7,
            type: "hub_unclean_restart",
            reason: "o processo 58 terminou sem se desligar em condições",
            occurrenceCount: 1,
            lastSeenAt: "2026-09-29T18:00:00Z",
        },
    ]);

    assert.doesNotMatch(markup, /Nada por ver/);
    assert.match(markup, /reiniciou-se sozinho/);
});
