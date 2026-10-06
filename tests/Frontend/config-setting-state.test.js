import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
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
    label: "Intervalo de localização",
    input: "number",
    fields: ["intervalTime"],
};

const sectionOf = (entry, row = null, stored = null) =>
    parseFragment(renderConfigSection("wonlex-json", entry, row, {}, false, null, stored))
        .querySelector("[data-config-section]");

test("a unidade cola-se ao campo em vez de andar solta", () => {
    const section = sectionOf(INTERVAL, { intervalTime: 3600 }, true);
    const group = section.querySelector(".input-group");

    assert.ok(group, "o campo devia estar num input-group");
    assert.ok(group.querySelector("input[type=\"number\"]"));
    assert.equal(group.querySelector(".input-group-text").textContent.trim(), "s");
});

/** O campo já mostra o valor; por baixo do nome só aparece o «era …» de uma edição. */
test("o valor guardado não se repete por baixo do nome", () => {
    const section = sectionOf({
        key: "autoHealth",
        capabilityKey: "auto_health",
        command: "HEALTHAUTOSET",
        label: "Medição automática de saúde",
        input: "number",
        fields: ["interval"],
    }, { interval: 60 }, true);

    assert.equal(section.querySelector("[data-config-summary]").textContent.trim(), "era 60 min");
});

test("uma definição editada mostra o valor que lá estava", () => {
    const section = sectionOf(INTERVAL, { intervalTime: 3600 }, true);

    assert.match(section.querySelector(".config-when-changed").textContent, /era 3600\s+s/);
});

test("o «Alterado» sobrepõe-se enquanto a edição estiver por enviar, sem apagar a entrega", () => {
    const host = document.createElement("div");
    host.innerHTML = `<div data-config-root><div data-config-pane="health"></div></div>`;
    const pane = host.querySelector("[data-config-pane]");
    pane.appendChild(sectionOf(INTERVAL, { intervalTime: 3600 }, true));
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

test("e ao ser editada diz que mudou, em vez do código que lá estava", () => {
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
