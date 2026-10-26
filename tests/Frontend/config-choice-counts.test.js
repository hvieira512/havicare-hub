import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { handleDeviceConfigChange } from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import { cacheElements } from "../../src/Dashboard/dashboard/core/dom.js";

/**
 * Os cartões de escolha não têm botão próprio: quem envia é o rodapé da secção. Se o clique
 * não acertar as contas, o valor fica escrito e não há por onde o enviar.
 */

const CATALOG = [{
    key: "fall_detection",
    capabilityKey: "fall_detection",
    command: "fall_detection",
    input: "fallSensitivity",
    fields: ["sensitivity"],
    options: { sensitivity: [{ value: 1 }, { value: 2 }, { value: 3 }] },
    category: "alarms",
}];

const CAPABILITIES = [
    { key: "fall_detection", section: "alarms", sectionLabel: "Alarmes", isConfigurable: true },
];

const render = () => {
    const host = document.createElement("div");
    host.appendChild(parseFragment(renderDeviceConfigurationRoot({
        protocol: "vivistar-iw",
        catalog: CATALOG,
        capabilityCatalog: CAPABILITIES,
        configurations: { fall_detection: { sensitivity: 2 } },
        capabilities: {},
    })));
    document.body.replaceChildren(host);
    captureConfigPristine(host);
    cacheElements({});
    syncConfigCounts(host);
    return host;
};

const sendButton = (root) =>
    root.querySelector("[data-config-pane=\"alarms\"] [data-action=\"saveConfigPane\"]");

const choose = (root, value) => {
    const radio = root.querySelector(`input[type="radio"][data-config-field="sensitivity"][value="${value}"]`);
    radio.checked = true;
    handleDeviceConfigChange({ target: radio });
};

test("choosing another level lights the section's send button", () => {
    const root = render();
    assert.equal(sendButton(root).disabled, true);

    choose(root, "3");

    assert.equal(sendButton(root).disabled, false);
});

test("going back to the starting level turns the send button off again", () => {
    const root = render();

    choose(root, "3");
    choose(root, "2");

    assert.equal(sendButton(root).disabled, true);
});
