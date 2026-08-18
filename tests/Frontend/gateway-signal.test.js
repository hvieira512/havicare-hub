import test from "node:test";
import assert from "node:assert/strict";

import {
    gatewaySignalRows,
    gatewaySignals,
    signalLabel,
} from "../../src/Dashboard/dashboard/devices/gateway-signal.js";

// Shaped exactly like the telemetry the hub publishes for a relayed device:
// gatewayId and rssiDbm live on source, never in data.
const telemetry = (gatewayId, rssiDbm, occurredAt) => ({
    payload: {
        type: "motion",
        occurredAt,
        data: {magnitudeMg: 1052},
        source: {protocol: "moko-w6r", gatewayId, rssiDbm},
    },
});

test("each gateway keeps its own latest signal", () => {
    const signals = gatewaySignals([
        telemetry("d48c49f7909c", -89, "2026-08-18T10:54:33Z"),
        telemetry("c5e390f30bce", -76, "2026-08-18T10:54:35Z"),
        telemetry("c5e390f30bce", -64, "2026-08-18T10:54:38Z"),
    ]);

    assert.equal(signals.size, 2);
    assert.equal(signals.get("c5e390f30bce").rssiDbm, -64);
    assert.equal(signals.get("d48c49f7909c").rssiDbm, -89);
});

test("the newest reading wins regardless of row order", () => {
    const signals = gatewaySignals([
        telemetry("c5e390f30bce", -64, "2026-08-18T10:54:38Z"),
        telemetry("c5e390f30bce", -76, "2026-08-18T10:54:35Z"),
    ]);

    assert.equal(signals.get("c5e390f30bce").rssiDbm, -64);
});

test("rows without a usable signal are skipped rather than shown as zero", () => {
    // A diaper sensor relayed before the decoder carried RSSI, and a watch that
    // reports over its own connection and has no gateway at all.
    const signals = gatewaySignals([
        {payload: {source: {gatewayId: "d48c49f7909c"}}},
        {payload: {source: {rssiDbm: -70}}},
        {payload: {data: {percent: 97}}},
    ]);

    assert.equal(signals.size, 0);
});

test("a gateway that has not been heard reads as unknown, not as near", () => {
    assert.equal(signalLabel(null), "—");
    assert.match(signalLabel({rssiDbm: -64, at: new Date().toISOString()}), /-64 dBm · há \d+s/);
});

test("every linked gateway gets a row, heard or not", () => {
    const html = gatewaySignalRows(
        [
            {deviceKey: "c5e390f30bce", model: "MKGW4"},
            {deviceKey: "dc1603ecf1f7", model: "MKGW4"},
        ],
        gatewaySignals([telemetry("c5e390f30bce", -64, new Date().toISOString())]),
    );

    assert.match(html, /c5e390f30bce/);
    assert.match(html, /-64 dBm/);
    // The gateway that never reported must still be listed, showing the gap.
    assert.match(html, /dc1603ecf1f7/);
    assert.match(html, /—/);
});

test("no linked devices renders nothing at all", () => {
    assert.equal(gatewaySignalRows([], new Map()), "");
});
