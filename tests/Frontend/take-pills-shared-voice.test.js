import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/** O 4P Touch aplica a mesma gravação a todos os lembretes, e nenhum deles tem a sua. */
const TAKE_PILLS = { input: "takePills", key: "take_pills", fields: [] };

const threeReminders = () => configSection(renderConfigInputs, TAKE_PILLS, {
    reminderSettings: [{ time: "09:00" }, { time: "13:00" }, { time: "21:00" }],
    voiceData: "AAAA",
});

test("a gravação de voz é uma só para todos os lembretes", () => {
    const section = threeReminders();

    assert.equal(section.querySelectorAll("[data-repeat-row=\"takePillsReminder\"]").length, 3);
    assert.equal(section.querySelectorAll("[data-config-field=\"voiceData\"]").length, 1);
    assert.equal(section.querySelectorAll("[data-action=\"takePillsRecord\"]").length, 1);
});

test("nenhum lembrete traz voz dentro nem sinal de a ter", () => {
    for (const row of threeReminders().querySelectorAll("[data-repeat-row=\"takePillsReminder\"]")) {
        assert.equal(row.querySelector("[data-takepills-audio]"), null, "voz dentro do lembrete");
        assert.equal(
            row.querySelector("[data-alarm-line-badges]").textContent.trim(),
            "",
            "sinal de voz na linha fechada",
        );
    }
});
