import test from "node:test";
import assert from "node:assert/strict";

import {renderRequestCardShell} from "../../src/Dashboard/dashboard/renderers.js";

test("MONIT condition renders as a status card without a request button", () => {
    const html = renderRequestCardShell(
        {feature: "diaper_condition", requestable: false},
        false,
        [{type: "diaper_condition", occurredAt: "2026-08-06T13:00:00Z", data: {state: "clean"}}],
    );

    assert.match(html, /Fralda limpa/);
    assert.match(html, /fa-baby/);
    assert.doesNotMatch(html, /data-action="requestFeature"/);
});

test("MONIT moisture renders its latest status without a request button", () => {
    const html = renderRequestCardShell(
        {feature: "diaper_moisture", requestable: false},
        false,
        [{
            type: "diaper_moisture",
            occurredAt: "2026-08-06T13:00:00Z",
            data: {maximumDelta: 7, affectedChannelCount: 2},
        }],
    );

    assert.match(html, /Delta 7 · 2 canais afetados/);
    assert.match(html, /fa-droplet/);
    assert.doesNotMatch(html, /data-action="requestFeature"/);
});
