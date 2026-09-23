import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { telemetryActivityRow } from "../../src/Dashboard/dashboard/devices/detail.js";

/**
 * A seta de abrir uma linha só aparece quando há mesmo mais para ver.
 *
 * A linha aberta mostrava o texto simples dos detalhes, que é o mesmo texto que a linha
 * fechada já mostra: quem carregava via «Tampa aberta: Não · Ligado à corrente: Sim» duas
 * vezes, uma por cima da outra. A seta prometia mais e não tinha.
 *
 * Há mais para ver em dois casos. Quando o renderizador declara um `detailsTitle` — a linha
 * visível é então um resumo, e o que ficou de fora vive ali; é o caso da presença, que mostra
 * as posturas e guarda as coordenadas. E quando os detalhes são mais do que um campo: na
 * linha vão todos seguidos numa corrida cortada ao fim da coluna, e abertos ficam um por
 * linha.
 */
test("uma linha que já diz tudo não abre", () => {
    const row = telemetryActivityRow({
        type: "lid_state",
        data: { open: true },
        occurredAt: "2026-09-23T10:00:00Z",
    });

    assert.notEqual(row.detail, "", "a linha fechada devia ter detalhes");
    assert.equal(row.expanded, "", "e não devia haver nada por trás deles");
});

/** Sem detalhes nenhuns também não há o que abrir. */
test("uma linha sem detalhes não abre", () => {
    const row = telemetryActivityRow({
        type: "device_status",
        data: { gsmSignalDbm: -25, signalLevel: 3 },
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

    assert.match(row.expanded, /<br>/);
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
