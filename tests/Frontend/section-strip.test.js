import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { sectionStrip } from "../../src/Dashboard/dashboard/components/chips.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * As secções escolhem-se sempre da mesma maneira: a pastilha do `sectionStrip`, com ícone e
 * com a contagem no `.count-number`.
 *
 * O painel de configuração desenhava a tira dele à mão, em separadores sublinhados com
 * distintivo do Bootstrap; o catálogo desenhava pastilhas sem ícone. Três aparências para o
 * mesmo gesto, e a diferença entre elas não dizia nada.
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

test("a tira de secções do painel de configuração é a pastilha partilhada", () => {
    const chips = renderConfigStrip().querySelectorAll(".capability-section-chip");

    assert.ok(chips.length >= 2);
    for (const chip of chips) {
        assert.ok(chip.querySelector("i.fa-solid"), "a pastilha ficou sem ícone");
        assert.ok(chip.querySelector(".count-number"), "a contagem não é a partilhada");
        assert.equal(chip.dataset.action, "selectConfigCategory");
        assert.notEqual(chip.dataset.section, undefined);
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
