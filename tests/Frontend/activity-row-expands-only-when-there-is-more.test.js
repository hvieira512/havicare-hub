import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { telemetryActivityRow } from "../../src/Dashboard/dashboard/devices/detail.js";

/**
 * Há mais para ver quando o renderizador declara um `detailsTitle` (a linha é um resumo) ou
 * quando os detalhes têm mais do que um campo, que na linha vão cortados.
 */
test("uma linha que já diz tudo não abre", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 0, chargingState: "absent" },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.notEqual(row.detail, "", "a linha fechada devia ter detalhes");
    assert.equal(row.expanded, "", "e não devia haver nada por trás deles");
});

test("uma linha sem detalhes não abre", () => {
    const row = telemetryActivityRow({
        type: "battery",
        data: { percent: 80 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.equal(row.expanded, "");
});

test("uma linha com mais do que um campo abre e arruma-os", () => {
    const row = telemetryActivityRow({
        type: "motion",
        data: { magnitudeMg: 120, xMg: 10, yMg: 20, zMg: 30 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.match(row.expanded, /\n/);
    assert.match(row.expanded, /20/);
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

/** A gaveta escapa o que recebe, `<br>` incluído. */
test("a gaveta não recebe marcação", () => {
    const row = telemetryActivityRow({
        type: "motion",
        data: { magnitudeMg: 120, xMg: 10, yMg: 20, zMg: 30 },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    // Com a gaveta vazia o teste passaria sem provar o escape.
    assert.notEqual(row.expanded, "", "a gaveta devia ter conteúdo para haver o que escapar");
    assert.doesNotMatch(row.expanded, /<br|&lt;/);
});
