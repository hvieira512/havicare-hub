import assert from "node:assert/strict";
import test, { beforeEach } from "node:test";

import { flush } from "./support/deferred-fetch.js";
import { cacheElements } from "../../src/Dashboard/dashboard/core/dom.js";
import { handleDeviceConfigChange } from "../../src/Dashboard/dashboard/devices/config/handlers.js";

const { state } = await import("../../src/Dashboard/dashboard/core/state.js");
const { renderDeviceConfigurationModal, saveDeviceConfiguration } =
    await import("../../src/Dashboard/dashboard/devices/config/panel.js");

/** Enviar uma definição volta a desenhar o painel, e o que se escreveu noutra não pode desaparecer. */

const entry = (key, category) => ({
    key,
    capabilityKey: key,
    command: key,
    input: "fallSensitivity",
    fields: ["sensitivity"],
    options: { sensitivity: [{ value: 1 }, { value: 2 }, { value: 3 }] },
    category,
});

let root;

beforeEach(() => {
    root = document.createElement("div");
    document.body.replaceChildren(root);
    cacheElements({ deviceConfigRoot: root });
    Object.assign(state.deviceModal, {
        imei: "865028000000308",
        protocol: "vivistar-iw",
        loading: false,
        catalogLoading: false,
        catalog: [entry("fall_detection", "alarms"), entry("sos_sensitivity", "alarms")],
        capabilityCatalog: [
            { key: "fall_detection", section: "alarms", sectionLabel: "Alarmes", isConfigurable: true },
            { key: "sos_sensitivity", section: "alarms", sectionLabel: "Alarmes", isConfigurable: true },
        ],
        enabledCapabilityKeys: [],
        configurations: { fall_detection: { sensitivity: 2 }, sos_sensitivity: { sensitivity: 2 } },
        configurationSync: { entries: {} },
        capabilities: {},
        configUi: {},
        actionDeliveries: {},
    });
    globalThis.fetch = async () => Response.json({
        configurations: { fall_detection: { sensitivity: 3 }, sos_sensitivity: { sensitivity: 2 } },
    });
    renderDeviceConfigurationModal();
});

const section = (key) => root.querySelector(`[data-config-section][data-config-key="${key}"]`);

const choose = (key, value) => {
    const radio = section(key).querySelector(`input[type="radio"][value="${value}"]`);
    radio.checked = true;
    handleDeviceConfigChange({ target: radio });
};

const chosen = (key) => section(key).querySelector("input[type=\"radio\"]:checked")?.value;

test("sending one setting keeps what was typed in another", async () => {
    choose("sos_sensitivity", "1");
    choose("fall_detection", "3");

    await saveDeviceConfiguration(section("fall_detection"));
    await flush();

    assert.equal(chosen("fall_detection"), "3");
    assert.equal(chosen("sos_sensitivity"), "1");
});
