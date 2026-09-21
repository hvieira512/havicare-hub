import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * Uma capacidade de telemetria que também se pede tem de ter de onde ser pedida.
 *
 * O `device_status` do dispensador pergunta ao aparelho o estado que ele tem agora, em vez de
 * se esperar pelo próximo heartbeat -- e no M228, cujos heartbeats vêm cifrados, é o único
 * caminho que há para a telemetria. O painel colocava cada entrada pela secção da capacidade,
 * e `telemetry` não é uma secção de configuração: a entrada era deitada fora em silêncio. Na
 * dashboard ficava declarada como pedível e sem nenhum botão que a pedisse.
 */

const render = (context) => parseFragment(renderDeviceConfigurationRoot({
    configurations: {},
    capabilities: {},
    ...context,
}));

test.afterEach(() => {
    state.protocols = [];
});

test("uma capacidade de telemetria pedível aparece em Sistema", () => {
    const root = render({
        protocol: "zayata-m228",
        catalog: [{
            key: "device_status",
            capabilityKey: "device_status",
            command: "readStatus",
            label: "Atualizar estado",
            input: "action",
            fields: [],
            category: "system",
            transient: true,
        }],
        capabilityCatalog: [{
            key: "device_status",
            label: "Estado do dispositivo",
            section: "telemetry",
            sectionLabel: "Telemetria",
            isTelemetry: true,
            isConfigurable: false,
            isRequestable: true,
        }],
    });

    const cards = [...root.querySelectorAll("[data-config-key]")]
        .map((card) => card.dataset.configKey);
    assert.ok(
        cards.includes("device_status"),
        `o cartão tem de existir, e só apareceram: ${cards.join(", ") || "nenhum"}`,
    );
});

test("uma capacidade de telemetria que não se pede continua fora do painel", () => {
    const root = render({
        protocol: "zayata-m228",
        catalog: [{
            key: "battery",
            capabilityKey: "battery",
            command: "battery",
            label: "Bateria",
            input: "json",
            fields: [],
        }],
        capabilityCatalog: [{
            key: "battery",
            label: "Bateria",
            section: "telemetry",
            sectionLabel: "Telemetria",
            isTelemetry: true,
            isConfigurable: false,
            isRequestable: false,
        }],
    });

    // O painel é de configuração: uma grandeza que só se lê não tem lá nada que fazer.
    assert.equal(root.querySelectorAll("[data-config-key]").length, 0);
});
