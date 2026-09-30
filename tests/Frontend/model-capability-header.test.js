import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const { renderCapabilitiesSection } = await import(
    "../../src/Dashboard/dashboard/settings/models/capabilities-editor.js",
);

/**
 * A ficha de um modelo contava o template numa casa e as capacidades ligáveis noutra, sem
 * dizer o que eram as que faltavam, e repetia o que o protocolo suporta por baixo de cada
 * linha. As duas coisas dizem-se uma vez.
 */

const catalogEntry = (key, section, flags) => ({
    key,
    section,
    sectionLabel: section === "telemetry" ? "Telemetria" : "Sistema",
    label: key,
    isTelemetry: false,
    isConfigurable: false,
    isEvent: false,
    isRequestable: false,
    ...flags,
});

const CATALOG = [
    catalogEntry("heart_rate", "telemetry", { isTelemetry: true, isRequestable: true }),
    catalogEntry("battery", "telemetry", { isTelemetry: true }),
    catalogEntry("steps", "telemetry", { isTelemetry: true }),
    // Uma acção: só se pede, não é leitura nem definição, e por isso não se liga aqui.
    catalogEntry("power_off", "settings_system", { isRequestable: true }),
    catalogEntry("find_device", "settings_system", { isRequestable: true }),
];

const MODEL = {
    id: 91,
    supplier: "4P Touch",
    commercialName: "D41",
    internalModel: "D41",
    deviceType: "watch",
    capabilities: { telemetry: { heart_rate: true, battery: true, steps: true } },
    requestableCapabilityKeys: ["heart_rate"],
};

let els;

beforeEach(() => {
    els = new Proxy({}, {
        get(target, name) {
            if (typeof name !== "string") return undefined;
            if (!(name in target)) target[name] = document.createElement("div");
            return target[name];
        },
    });
    initSettingsModels({ els, ui: {} });
    state.settingsModal.currentCapabilitiesModel = MODEL;
    state.settingsModal.capabilityCatalog = CATALOG;
    state.settingsModal.capabilityModelTemplateKeys = CATALOG.map((entry) => entry.key);
    state.settingsModal.capabilityEnabledCapabilities = ["heart_rate", "battery", "steps"];
    state.settingsModal.capabilityRequestableCapabilities = ["heart_rate"];
    state.settingsModal.activeCapabilitySection = "telemetry";
    renderCapabilitiesSection();
});

test("o cabeçalho diz o que são as capacidades do template que não se ligam aqui", () => {
    assert.equal(
        els.capabilitySubtitle.textContent,
        "4P Touch — 5 capacidades no template: 3 ligam-se aqui e 2 são ações, que só se pedem ao aparelho.",
    );
    assert.equal(els.capabilitySummary.textContent, "3/3 ativos");
});

test("o que o protocolo suporta sobe uma vez para o cabeçalho da secção", () => {
    const header = els.capabilityGroups.querySelector("[data-section-note]");
    assert.ok(header, "a secção não explica o protocolo");
    assert.equal(
        header.textContent,
        "O 4P Touch envia estas leituras; 1 delas também pode ser pedida.",
    );
});

test("nenhuma das linhas repete o que o protocolo suporta", () => {
    const rows = [...els.capabilityGroups.querySelectorAll(".capability-model-row")];
    assert.equal(rows.length, 3);
    for (const row of rows) {
        assert.doesNotMatch(row.textContent, /receção|não suporta pedido/i);
    }
});
