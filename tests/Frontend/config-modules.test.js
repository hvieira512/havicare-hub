import test from "node:test";
import assert from "node:assert/strict";

import { asText, parseFragment } from "./support/dom.js";
import { readTakePills, takePillsInput as buildTakePillsInput } from "../../src/Dashboard/dashboard/devices/config/four-p-touch-take-pills.js";

const takePillsInput = asText(buildTakePillsInput);
const entry = { key: "medication_reminders", capabilityKey: "medication_reminders", limit: 1 };
const plan = (time, recurrence) => ({ times: [{ time, enabled: true, recurrence }] });

test("4P Touch medication UI escapes values and respects the reminder limit", () => {
    const html = takePillsInput(entry, {
        reminderText: "<script>alert(\"x\")</script>",
        plans: [plan("08:00", { kind: "once" }), plan("20:00", { kind: "daily" })],
    }, { limit: 1 });

    assert.match(html, /&lt;script&gt;alert\(&quot;x&quot;\)&lt;\/script&gt;/);
    assert.equal((html.match(/data-repeat-row="alarm_clock"/g) || []).length, 1);
    assert.match(html, /data-action="addRepeatRow" data-repeat-kind="alarm_clock" disabled/);
});

/** Os lembretes são linhas de alarme: a recorrência personalizada marca os dias, de 1 a 7. */
test("a custom reminder ticks its days and reads them back", () => {
    const root = parseFragment(takePillsInput({ ...entry, limit: 3 }, {
        reminderText: "Tomar",
        plans: [plan("09:30", { kind: "custom", days: [1, 2, 3, 4, 5] })],
    }, { limit: 3 }));

    assert.deepEqual(
        Array.from(root.querySelectorAll("[data-weekday]:checked")).map((input) => input.value),
        ["1", "2", "3", "4", "5"],
    );
    assert.deepEqual(readTakePills(root).plans, [plan("09:30", { kind: "custom", days: [1, 2, 3, 4, 5] })]);
});
