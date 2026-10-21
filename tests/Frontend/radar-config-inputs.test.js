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

test("a sensibilidade parte do valor declarado e lê-se como inteiro", () => {
    const root = section(sensitivity);

    assert.deepEqual(readConfigPayload(root), { sensitivity: 5 });
    assert.equal(root.querySelector("input[type=\"range\"]").max, "99");
    field(root, "sensitivity").value = "40";
    assert.deepEqual(readConfigPayload(root), { sensitivity: 40 });
});

test("um intervalo de valores lê os dois extremos com os nomes da definição", () => {
    const root = section(breathRate);

    assert.deepEqual(readConfigPayload(root), { minPerMinute: 8, maxPerMinute: 24 });
    assert.match(root.textContent, /até/);
    assert.match(root.textContent, /\/min/);
});

test("um intervalo com o mínimo acima do máximo não se envia", () => {
    const root = section(breathRate, { minPerMinute: 30, maxPerMinute: 24 });

    assert.throws(() => readConfigPayload(root), /mínimo/);
});

test("o interruptor com valor guarda o valor mesmo desligado", () => {
    const root = section(weakVitals);

    assert.deepEqual(readConfigPayload(root), { enabled: false, afterMinutes: 10 });
    field(root, "enabled").checked = true;
    field(root, "afterMinutes").value = "15";
    assert.deepEqual(readConfigPayload(root), { enabled: true, afterMinutes: 15 });
});

test("o interruptor fica à esquerda e o valor à direita", () => {
    const root = section(weakVitals);
    const order = [...root.querySelectorAll("[data-config-field]")].map((node) => node.dataset.configField);

    assert.deepEqual(order, ["enabled", "afterMinutes"]);
    assert.equal(root.querySelector(".input-group-text").textContent, "min");
});

test("o número do alarme de queda mostra a unidade e o passo da definição", () => {
    const root = parseFragment(renderConfigSection(PROTOCOL, fallAlarm, null));

    assert.equal(root.querySelector(".input-group-text")?.textContent.trim(), "s");
    assert.equal(field(root, "afterSeconds").step, "10");
    assert.equal(field(root, "afterSeconds").value, "60");
});

test("os cartões de opção leem o valor em texto", () => {
    const root = section(workingMode);

    assert.deepEqual(readConfigPayload(root), { mode: "full_function" });
    root.querySelector("input[value=\"people_tracking\"]").checked = true;
    assert.deepEqual(readConfigPayload(root), { mode: "people_tracking" });
    assert.match(root.textContent, /Segue várias pessoas/);
});

test("a instalação lê a montagem e a altura", () => {
    const root = section(installation);

    assert.deepEqual(readConfigPayload(root), { mounting: "ceiling", heightCm: 280 });
    root.querySelector("input[value=\"wall\"]").checked = true;
    field(root, "heightCm").value = "180";
    assert.deepEqual(readConfigPayload(root), { mounting: "wall", heightCm: 180 });
    assert.match(root.textContent, /Alturas recomendadas/);
});

test("os limites de deteção partem dos de fábrica do teto e aceitam décimas", () => {
    const root = section(boundary);

    assert.deepEqual(readConfigPayload(root), { leftM: 3, rightM: 3, frontM: 2, backM: 2 });
    field(root, "frontM").value = "2.7";
    assert.equal(readConfigPayload(root).frontM, 2.7);
});

test("mudar a montagem para parede põe os limites de fábrica da parede", async () => {
    const { applyFactoryBoundary } = await import("../../src/Dashboard/dashboard/devices/config/inputs/radar.js");
    const pane = parseFragment(`<div data-config-pane>${renderConfigSection(PROTOCOL, boundary, null)}</div>`).firstElementChild;

    applyFactoryBoundary(pane, "wall");

    assert.deepEqual(readConfigPayload(pane.querySelector("[data-config-section]")), { leftM: 3, rightM: 3, frontM: 4, backM: 0 });
});

test("as áreas não se editam no cartão e voltam tal como vieram", () => {
    const items = [
        { key: 0, type: 5, name: "Cama de monitorização", xMinDm: -4, yMinDm: -6, xMaxDm: 6, yMaxDm: 13 },
        { key: 1, type: 4, name: "Porta", xMinDm: 17, yMinDm: 15, xMaxDm: 20, yMaxDm: 18 },
    ];
    const root = section(areas, { items });

    assert.deepEqual(readConfigPayload(root), { items });
    assert.match(root.textContent, /2 áreas: Cama de monitorização, Porta/);
    assert.ok(root.querySelector("[data-action=\"openRadarAreas\"]"));
});

test("os campos novos do radar desenham-se em cartão, com os campos por baixo", () => {
    for (const entry of [sensitivity, breathRate, weakVitals, workingMode, installation, boundary, areas]) {
        const root = parseFragment(renderConfigSection(PROTOCOL, entry, null));

        assert.ok(root.querySelector("[data-config-section] [data-config-form]"), entry.key);
    }
});
