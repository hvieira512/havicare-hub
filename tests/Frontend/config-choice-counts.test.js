import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    initDeviceConfigPanel,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { handleDeviceConfigClick } from "../../src/Dashboard/dashboard/devices/config/handlers.js";

/**
 * Os cartões de escolha não têm botão próprio: quem envia é o rodapé da secção. Se o clique
 * não acertar as contas, o valor fica escrito e não há por onde o enviar.
 */

const CATALOG = [{
    key: "fall_detection",
    capabilityKey: "fall_detection",
    command: "fall_detection",
    label: "Deteção de queda",
    input: "fallSensitivity",
    fields: ["sensitivity"],
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
    initDeviceConfigPanel({ els: {} });
    syncConfigCounts(host);
    return host;
};

const sendButton = (root) =>
    root.querySelector("[data-config-pane=\"alarms\"] [data-action=\"saveConfigPane\"]");

const choiceButton = (root, value) =>
    root.querySelector(`[data-action="selectConfigChoice"][data-config-value="${value}"]`);

test("escolher outro nível acende o enviar da secção", () => {
    const root = render();
    assert.equal(sendButton(root).disabled, true);

    const button = choiceButton(root, "3");
    button.dispatchEvent(new window.Event("click", { bubbles: true }));
    handleDeviceConfigClick({ target: button });

    assert.equal(root.querySelector("[data-config-field=\"sensitivity\"]").value, "3");
    assert.equal(sendButton(root).disabled, false);
});

test("voltar ao nível de partida apaga o enviar outra vez", () => {
    const root = render();

    handleDeviceConfigClick({ target: choiceButton(root, "3") });
    handleDeviceConfigClick({ target: choiceButton(root, "2") });

    assert.equal(sendButton(root).disabled, true);
});
