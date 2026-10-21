import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    initDeviceConfigPanel,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * A pastilha diz o que aconteceu ao valor do lado do aparelho e o «Alterado» o que está por
 * enviar do lado de cá; o segundo sobrepõe-se ao primeiro enquanto houver edição.
 */

const INTERVAL = {
    key: "locationInterval",
    capabilityKey: "location_reporting_interval",
    command: "locationInterval",
    input: "number",
    fields: ["intervalSeconds"],
};

const sectionOf = (entry, row = null, stored = null) =>
    parseFragment(renderConfigSection("wonlex-json", entry, row, {}, false, null, stored))
        .querySelector("[data-config-section]");

/** O campo já mostra o valor; por baixo do nome só aparece o «era …» de uma edição. */
test("the stored value is not repeated under the name", () => {
    const section = sectionOf({
        key: "autoHealth",
        capabilityKey: "auto_health",
        command: "HEALTHAUTOSET",
        input: "number",
        fields: ["intervalMinutes"],
    }, { intervalMinutes: 60 }, true);

    assert.equal(section.querySelector("[data-config-summary]").textContent.trim(), "era 60 min");
});

test("an edited setting shows the value that was there", () => {
    const section = sectionOf(INTERVAL, { intervalSeconds: 3600 }, true);

    assert.match(section.querySelector(".config-when-changed").textContent, /era 3600\s+s/);
});

test("«Alterado» takes over while the edit is unsent, without erasing the delivery", () => {
    const host = document.createElement("div");
    host.innerHTML = `<div data-config-root><div data-config-pane="health"></div></div>`;
    const pane = host.querySelector("[data-config-pane]");
    pane.appendChild(sectionOf(INTERVAL, { intervalSeconds: 3600 }, true));
    document.body.replaceChildren(host);
    captureConfigPristine(host);
    initDeviceConfigPanel({ els: {} });

    const section = host.querySelector("[data-config-section]");
    assert.equal("configEdited" in section.dataset, false);
    assert.match(section.querySelector(".config-when-clean .state-badge").textContent, /Aplicado/);
    assert.match(section.querySelector(".config-when-changed .state-badge").textContent, /Alterado/);

    section.querySelector("[data-config-field]").value = "900";
    syncConfigCounts(host);

    assert.equal(section.dataset.configEdited, "1");
});

test("and once edited it says it changed, instead of the code that was there", () => {
    const section = sectionOf({
        key: "alarmVolume",
        capabilityKey: "alarm_volume",
        command: "volume",
        label: "Volume",
        input: "choice",
        fields: ["volume"],
    }, { volume: 2 }, true);

    assert.equal(section.querySelector(".config-when-changed").textContent.trim(), "alterada e por enviar");
});

/** Um intervalo tem dois números: o que lá estava diz-se inteiro, e não só o mínimo. */
test("an edited interval shows both ends that were there", () => {
    const range = {
        key: "heart_rate_alert",
        capabilityKey: "heart_rate_alert",
        command: "heart_rate_alert",
        label: "Frequência cardíaca fora do intervalo",
        input: "valueRange",
        fields: ["minBpm", "maxBpm"],
        options: { min: 0, max: 255, unit: "bpm" },
    };
    const section = parseFragment(
        renderConfigSection("qinglanst-radar", range, { minBpm: 50, maxBpm: 90 }, {}, false, null, true),
    ).querySelector("[data-config-section]");

    assert.match(section.querySelector(".config-when-changed").textContent, /era 50–90\s+bpm/);
});
