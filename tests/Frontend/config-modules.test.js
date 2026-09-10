import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import {
    takePillsInput,
    takePillsReminderGroup,
} from "../../src/Dashboard/dashboard/devices/config/four-p-touch-take-pills.js";

test("4P Touch medication UI escapes values and respects the reminder limit", () => {
    const html = takePillsInput({
        reminderText: "<script>alert(\"x\")</script>",
        reminderSettings: [
            { time: "08:00", enabled: true, frequency: 1 },
            { time: "20:00", enabled: false, frequency: 2 },
        ],
    }, { limit: 1 });

    assert.match(html, /&lt;script&gt;alert\(&quot;x&quot;\)&lt;\/script&gt;/);
    assert.equal((html.match(/data-takepills-reminder-group=/g) || []).length, 1);
    assert.match(html, /data-action="addRepeatRow" data-repeat-kind="takePillsReminder" disabled/);
});

/** A máscara `0111110` tem o domingo na posição 0: são segunda a sexta. */
test("4P Touch custom-frequency reminder marca os dias da máscara, e não a máscara", () => {
    const html = takePillsReminderGroup(
        { time: "09:30", enabled: true, frequency: 3, custom: "0111110" },
        0,
        [{ value: 3, label: "Personalizado" }],
    );
    const root = parseFragment(html);

    assert.match(html, /data-takepills-custom-wrapper="0"/);
    assert.doesNotMatch(html, /data-takepills-custom-wrapper="0"[^>]*d-none/);
    assert.deepEqual(
        Array.from(root.querySelectorAll("[data-weekday]:checked")).map((i) => i.value),
        ["1", "2", "3", "4", "5"],
    );
    // A máscara deixou de andar na marcação: quem a escrevia à mão passa a carregar em dias.
    assert.doesNotMatch(html, /value="0111110"/);
});
