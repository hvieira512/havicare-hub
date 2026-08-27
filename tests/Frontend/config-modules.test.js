import test from "node:test";
import assert from "node:assert/strict";

import {
    takePillsInput,
    takePillsReminderGroup,
} from "../../src/Dashboard/dashboard/devices/config/four-p-touch-take-pills.js";

test("4P Touch medication UI escapes values and respects the reminder limit", () => {
    const html = takePillsInput({
        reminderText: '<script>alert("x")</script>',
        reminderSettings: [
            {time: "08:00", enabled: true, frequency: 1},
            {time: "20:00", enabled: false, frequency: 2},
        ],
    }, {limit: 1});

    assert.match(html, /&lt;script&gt;alert\(&quot;x&quot;\)&lt;\/script&gt;/);
    assert.equal((html.match(/data-takepills-reminder-group=/g) || []).length, 1);
    assert.match(html, /data-action="addRepeatRow" data-repeat-kind="takePillsReminder" disabled/);
});

test("4P Touch custom-frequency reminder renders the custom field", () => {
    const html = takePillsReminderGroup(
        {time: "09:30", enabled: true, frequency: 3, custom: "0111110"},
        0,
        [{value: 3, label: "Personalizado"}],
    );

    assert.match(html, /data-takepills-custom-wrapper="0"/);
    assert.doesNotMatch(html, /data-takepills-custom-wrapper="0"[^>]*d-none/);
    assert.match(html, /value="0111110"/);
});
