import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { downlinkActivityRow: buildRow } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

// Os campos de marcação do descritor são fragmentos; as assertivas de texto querem texto.
const downlinkActivityRow = (payload) => {
    const row = buildRow(payload);
    return { ...row, sub: String(row.sub ?? ""), expanded: String(row.expanded ?? "") };
};

/**
 * Um pedido confirmado tem valor -- numa localização são as coordenadas -- e a coluna do
 * valor está ocupada pela pastilha do estado. O valor entra por baixo do nome e a gaveta
 * guarda o resto.
 */
const locationReport = {
    payload: {
        type: "location",
        occurredAt: "2026-09-29T10:35:26Z",
        data: {
            lat: 38.7369,
            lon: -9.1427,
            source: "gps",
            accuracyMeters: 12,
        },
    },
};

const locationRequest = {
    id: "c1",
    feature: "location",
    status: "acked",
    requestedAt: "2026-09-29T10:35:25Z",
    sentAt: "2026-09-29T10:35:25Z",
    ackedAt: "2026-09-29T10:35:26Z",
};

beforeEach(() => {
    state.selectedImei = "861265062544868";
    state.selectedDetail = { recent: { telemetry: [locationReport] } };
});

test("a resposta de uma localização põe as coordenadas por baixo do nome", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.match(row.sub, /38\.73690, -9\.14270/);
});

test("a precisão e a fonte ficam na gaveta", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.match(row.expanded, /GPS/);
    assert.match(row.expanded, /±12 m/);
});

test("a gaveta diz quando se pediu e quanto tempo a resposta demorou", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.match(row.expanded, /Pedido às \d{2}:\d{2}:\d{2}/);
    assert.match(row.expanded, /1 s depois/);
});

/** Sem resposta não há valor nenhum a mostrar, e a gaveta diz o que se está à espera. */
test("um pedido por responder não inventa valor", () => {
    const row = downlinkActivityRow({
        ...locationRequest,
        status: "queued",
        sentAt: "",
        ackedAt: "",
        expectedReplyTypes: ["AP76"],
    });

    assert.equal(row.sub, "");
    assert.match(row.expanded, /À espera de AP76/);
});

/** Uma leitura anterior ao pedido é de outra resposta, e não desta. */
test("uma leitura anterior ao pedido não conta como resposta", () => {
    state.selectedDetail.recent.telemetry = [
        {
            payload: {
                ...locationReport.payload,
                occurredAt: "2026-09-29T09:00:00Z",
            },
        },
    ];

    assert.equal(downlinkActivityRow(locationRequest).sub, "");
});

/** A gaveta escapa o que recebe: marcação em texto aparecia à letra. */
test("a gaveta não recebe marcação", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.doesNotMatch(row.expanded, /<br|<span|&lt;/);
});
