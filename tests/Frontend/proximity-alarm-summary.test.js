import test from "node:test";
import assert from "node:assert/strict";

import { proximityAlarmSummaryCard as buildCard } from "../../src/Dashboard/dashboard/devices/event-summary-cards.js";
import { asText } from "./support/dom.js";

const proximityAlarmSummaryCard = asText(buildCard);

const alarm = (gatewayId, occurredAt) => ({
    type: "proximity_alarm",
    occurredAt,
    data: { gatewayId, rssiDbm: -49, window: { maxDbm: -49, medianDbm: -53, minDbm: -61, samples: 7 } },
});

test("a device that never raised it gets no card", () => {
    assert.equal(proximityAlarmSummaryCard([{ type: "battery", data: { percent: 90 } }]), "");
});

test("the card names the latest alarm's gateway", () => {
    const html = proximityAlarmSummaryCard([
        alarm("d48c49f7909c", "2026-10-23T09:00:00Z"),
        alarm("c5e390f30bce", "2026-10-23T10:42:07Z"),
    ]);

    assert.match(html, /Último alarme de proximidade/);
    assert.match(html, /gateway c5e390f30bce/);
    assert.doesNotMatch(html, /d48c49f7909c/);
});

test("the event's row reads the strongest reading and the gateway, not raw fields", async () => {
    const { uplinkCardContent } = await import("../../src/Dashboard/dashboard/components/cards/telemetry.js");
    const card = uplinkCardContent("proximity_alarm", alarm("c5e390f30bce", "2026-10-23T10:42:07Z").data);
    const text = `${card.value} ${String(card.details)}`;

    assert.match(text, /-49 dBm/);
    assert.match(text, /gateway c5e390f30bce/);
    assert.match(text, /7 leituras/);
    assert.doesNotMatch(text, /Sem tradução|object Object/);
});
