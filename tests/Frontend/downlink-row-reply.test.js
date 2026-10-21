import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { downlinkActivityRow: buildRow } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

const downlinkActivityRow = (payload) => {
    const row = buildRow(payload);
    return { ...row, sub: String(row.sub ?? ""), expanded: String(row.expanded ?? "") };
};

/**
 * A coluna do valor é da pastilha do estado: o valor de um pedido confirmado, como as
 * coordenadas, entra por baixo do nome e a gaveta guarda o resto.
 */
const locationReport = {
    payload: {
        type: "location",
        occurredAt: "2026-09-29T10:35:26Z",
        data: {
            hasCoordinates: true,
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

test("a location reply puts the coordinates under the name", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.match(row.sub, /38\.73690, -9\.14270/);
});

test("accuracy and source go in the drawer", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.match(row.expanded, /GPS/);
    assert.match(row.expanded, /±12 m/);
});

test("the drawer says when it was requested and how long the reply took", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.match(row.expanded, /Pedido às \d{2}:\d{2}:\d{2}/);
    assert.match(row.expanded, /1 s depois/);
});

/** Sem resposta não há valor nenhum a mostrar, e a gaveta diz o que se está à espera. */
test("an unanswered request does not make up a value", () => {
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
test("a reading older than the request does not count as its reply", () => {
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

test("the drawer takes no markup", () => {
    const row = downlinkActivityRow(locationRequest);

    assert.doesNotMatch(row.expanded, /<br|<span|&lt;/);
});
