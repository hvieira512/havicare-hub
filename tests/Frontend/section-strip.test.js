import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { sectionStrip } from "../../src/Dashboard/dashboard/components/chips.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * As secções escolhem-se sempre com o mesmo vocabulário: o ícone da secção, o nome e a
 * contagem. A forma é que muda com o número -- o catálogo tem-nas em tira, e o painel de
 * configuração em lista, porque lá chegam a seis e uma tira corta.
 */
test.afterEach(() => {
    state.protocols = [];
});

const catalogEntry = (key, category) => ({
    key,
    capabilityKey: key,
    command: key,
    label: key,
    input: "json",
    category,
});

const renderConfigStrip = () => parseFragment(renderDeviceConfigurationRoot({
    protocol: "veepoo-ble",
    catalog: [catalogEntry("heart_rate", "health"), catalogEntry("phonebook", "contacts")],
    configurations: {},
    capabilities: {},
    capabilityCatalog: [
        { key: "heart_rate", section: "health", sectionLabel: "Saúde", isConfigurable: true },
        { key: "phonebook", section: "contacts", sectionLabel: "Contactos", isConfigurable: true },
    ],
}));

test("a lista de secções do painel de configuração diz o mesmo que a tira", () => {
    const links = renderConfigStrip().querySelectorAll("[data-config-section-link]");

    assert.ok(links.length >= 2);
    for (const link of links) {
        assert.ok(link.querySelector("i.fa-solid"), "a linha ficou sem ícone");
        assert.ok(link.querySelector("[data-config-section-total]"), "a linha ficou sem contagem");
        assert.equal(link.dataset.action, "selectConfigCategory");
        assert.notEqual(link.dataset.section, undefined);
    }
});

test("o painel de configuração não desenha mais separadores sublinhados", () => {
    const root = renderConfigStrip();

    assert.equal(root.querySelector(".nav-underline"), null);
    assert.equal(root.querySelector("[data-config-category]"), null);
});

test("o componente desenha sempre o ícone e a contagem partilhada", () => {
    const strip = parseFragment(sectionStrip(
        [{ key: "telemetry", label: "Telemetria", count: 17, icon: "fa-chart-line" }],
        "jumpCapabilitySection",
        "telemetry",
    ));
    const chip = strip.querySelector(".capability-section-chip");

    assert.ok(chip.classList.contains("selected"));
    assert.ok(chip.querySelector("i.fa-chart-line"));
    assert.equal(chip.querySelector(".count-number").textContent, "17");
});
