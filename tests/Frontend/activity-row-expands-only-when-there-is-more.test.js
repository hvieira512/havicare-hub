import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { telemetryActivityRow } from "../../src/Dashboard/dashboard/devices/detail.js";

/**
 * A seta de abrir uma linha só aparece quando há mesmo mais para ver.
 *
 * Há mais para ver em dois casos: quando o renderizador declara um `detailsTitle` -- a linha
 * visível é então um resumo, como na presença, que guarda as coordenadas --, e quando os
 * detalhes são mais do que um campo, que na linha vão cortados ao fim da coluna.
 */
test("uma linha que já diz tudo não abre", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 80, chargingState: "charging" },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.notEqual(row.detail, "", "a linha fechada devia ter detalhes");
    assert.equal(row.expanded, "", "e não devia haver nada por trás deles");
});

/** Sem detalhes nenhuns também não há o que abrir. */
test("uma linha sem detalhes não abre", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 80 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.equal(row.expanded, "");
});

/** Vários campos numa corrida só: abertos, um por linha. */
test("uma linha com mais do que um campo abre e arruma-os", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 80, chargingState: "charging", mainsPowered: true },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.match(row.expanded, /\n/);
    assert.match(row.expanded, /corrente/i);
});

test("uma linha cujo resumo esconde alguma coisa abre", () => {
    const row = telemetryActivityRow({
        type: "presence",
        data: {
            personCount: 2,
            people: [
                { postureType: "lying_down", x: 120, y: 30 },
                { postureType: "standing", x: 10, y: 200 },
            ],
        },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.notEqual(row.expanded, "");
});

/** A gaveta escapa o que recebe: marcação em texto aparecia à letra, `<br>` incluído. */
test("a gaveta não recebe marcação", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 80, chargingState: "charging", mainsPowered: true },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.doesNotMatch(row.expanded, /<br|&lt;/);
});
