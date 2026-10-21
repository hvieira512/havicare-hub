import test from "node:test";
import assert from "node:assert/strict";

import { configSection, parseFragment } from "./support/dom.js";
import {
    defaultConfigPayload,
    readConfigPayload,
    renderConfigInputs,
    renderConfigSection,
} from "../../src/Dashboard/dashboard/devices/config/index.js";

const PROTOCOL = "qinglanst-radar";
const section = (entry, desired) =>
    configSection(renderConfigInputs, entry, desired ?? defaultConfigPayload(entry, PROTOCOL), { protocol: PROTOCOL });
const field = (root, name) => root.querySelector(`[data-config-field="${name}"]`);

const sensitivity = {
    key: "vital_sign_sensitivity",
    input: "sensitivitySlider",
    fields: ["sensitivity"],
    options: { min: 1, max: 99, default: 5 },
};

const breathRate = {
    key: "breath_rate_alert",
    input: "valueRange",
    fields: ["minPerMinute", "maxPerMinute"],
    options: { min: 0, max: 255, unit: "/min", default: { minPerMinute: 8, maxPerMinute: 24 } },
};

const weakVitals = {
    key: "weak_vital_signs_alarm",
    input: "switchWithValue",
    fields: ["enabled", "afterMinutes"],
    options: { unit: "min", min: 1, max: 60, default: { enabled: false, afterMinutes: 10 } },
};

const fallAlarm = {
    key: "fall_alarm",
    input: "number",
    fields: ["afterSeconds"],
    options: { min: 10, max: 300, step: 10, unit: "s", default: 60 },
};

const workingMode = {
    key: "working_mode",
    input: "optionCards",
    fields: ["mode"],
    options: {
        mode: [
            { value: "people_tracking", label: "Trajetos", description: "Segue várias pessoas.", icon: "fa-route" },
            { value: "full_function", label: "Completo", description: "Tudo.", icon: "fa-table-cells-large" },
        ],
        default: "full_function",
    },
};

const installation = {
    key: "radar_installation",
    input: "radarInstallation",
    fields: ["mounting", "heightCm"],
    options: {
        mounting: [
            { value: "ceiling", label: "Teto", description: "Recomendado entre 2 e 3 m." },
            { value: "wall", label: "Parede", description: "Entre 1,5 e 2,3 m, conforme o modo." },
        ],
        heightCm: { min: 150, max: 330, step: 10 },
        default: { mounting: "ceiling", heightCm: 280 },
    },
};

const boundary = {
    key: "detection_boundary",
    input: "radarBoundary",
    fields: ["leftM", "rightM", "frontM", "backM"],
    options: { step: 0.1, ceiling: { leftM: 3, rightM: 3, frontM: 2, backM: 2 }, wall: { leftM: 3, rightM: 3, frontM: 4, backM: 0 } },
};

const areas = { key: "radar_areas", input: "radarAreas", fields: ["items"] };

test("sensitivity starts from the declared value and reads as an integer", () => {
    const root = section(sensitivity);

    assert.deepEqual(readConfigPayload(root), { sensitivity: 5 });
    assert.equal(root.querySelector("input[type=\"range\"]").max, "99");
    field(root, "sensitivity").value = "40";
    assert.deepEqual(readConfigPayload(root), { sensitivity: 40 });
});

test("a value range reads both ends with the definition's names", () => {
    const root = section(breathRate);

    assert.deepEqual(readConfigPayload(root), { minPerMinute: 8, maxPerMinute: 24 });
    assert.match(root.textContent, /até/);
    assert.match(root.textContent, /\/min/);
});

test("a range with the minimum above the maximum is not sent", () => {
    const root = section(breathRate, { minPerMinute: 30, maxPerMinute: 24 });

    assert.throws(() => readConfigPayload(root), /mínimo/);
});

test("a switch with a value keeps the value even when off", () => {
    const root = section(weakVitals);

    assert.deepEqual(readConfigPayload(root), { enabled: false, afterMinutes: 10 });
    field(root, "enabled").checked = true;
    field(root, "afterMinutes").value = "15";
    assert.deepEqual(readConfigPayload(root), { enabled: true, afterMinutes: 15 });
});

test("the switch sits left and the value right", () => {
    const root = section(weakVitals);
    const order = [...root.querySelectorAll("[data-config-field]")].map((node) => node.dataset.configField);

    assert.deepEqual(order, ["enabled", "afterMinutes"]);
    assert.equal(root.querySelector(".input-group-text").textContent, "min");
});

test("the fall alarm number shows the definition's unit and step", () => {
    const root = parseFragment(renderConfigSection(PROTOCOL, fallAlarm, null));

    assert.equal(root.querySelector(".input-group-text")?.textContent.trim(), "s");
    assert.equal(field(root, "afterSeconds").step, "10");
    assert.equal(field(root, "afterSeconds").value, "60");
});

test("option cards read the value as text", () => {
    const root = section(workingMode);

    assert.deepEqual(readConfigPayload(root), { mode: "full_function" });
    root.querySelector("input[value=\"people_tracking\"]").checked = true;
    assert.deepEqual(readConfigPayload(root), { mode: "people_tracking" });
    assert.match(root.textContent, /Segue várias pessoas/);
});

test("installation reads the mounting and the height", () => {
    const root = section(installation);

    assert.deepEqual(readConfigPayload(root), { mounting: "ceiling", heightCm: 280 });
    root.querySelector("input[value=\"wall\"]").checked = true;
    field(root, "heightCm").value = "180";
    assert.deepEqual(readConfigPayload(root), { mounting: "wall", heightCm: 180 });
    assert.match(root.textContent, /Alturas recomendadas/);
});

test("detection limits start from the ceiling factory values and accept decimals", () => {
    const root = section(boundary);

    assert.deepEqual(readConfigPayload(root), { leftM: 3, rightM: 3, frontM: 2, backM: 2 });
    field(root, "frontM").value = "2.7";
    assert.equal(readConfigPayload(root).frontM, 2.7);
});

test("switching the mounting to wall sets the wall factory limits", async () => {
    const { applyFactoryBoundary } = await import("../../src/Dashboard/dashboard/devices/config/inputs/radar.js");
    const pane = parseFragment(`<div data-config-pane>${renderConfigSection(PROTOCOL, boundary, null)}</div>`).firstElementChild;

    applyFactoryBoundary(pane, "wall");

    assert.deepEqual(readConfigPayload(pane.querySelector("[data-config-section]")), { leftM: 3, rightM: 3, frontM: 4, backM: 0 });
});

test("areas are not edited in the card and read back unchanged", () => {
    const items = [
        { key: 0, type: "monitoring_bed", name: "Cama de monitorização", xMinDm: -4, yMinDm: -6, xMaxDm: 6, yMaxDm: 13 },
        { key: 1, type: "door", name: "Porta", xMinDm: 17, yMinDm: 15, xMaxDm: 20, yMaxDm: 18 },
    ];
    const root = section(areas, { items });

    assert.deepEqual(readConfigPayload(root), { items });
    assert.match(root.textContent, /2 áreas: Cama de monitorização, Porta/);
    assert.ok(root.querySelector("[data-action=\"openRadarAreas\"]"));
});

test("the radar's new fields draw as a card with the fields below", () => {
    for (const entry of [sensitivity, breathRate, weakVitals, workingMode, installation, boundary, areas]) {
        const root = parseFragment(renderConfigSection(PROTOCOL, entry, null));

        assert.ok(root.querySelector("[data-config-section] [data-config-form]"), entry.key);
    }
});
