import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import {JSDOM} from "jsdom";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {
    initDiaperSensitivityUi,
    loadDiaperSensitivity,
    selectedDiaperSensitivity,
} from "../../src/Dashboard/dashboard/devices/diaper-sensitivity-ui.js";

/**
 * O selector de sensibilidade do medidor de fraldas.
 *
 * O primeiro teste existe porque a primeira versao punha esta linha no separador
 * "Geral", ao lado dos gateways autorizados. Gateways sao autorizacao; sensibilidade e
 * configuracao, e pertence ao separador das configuracoes. Uma revisao apanhou-o, e
 * este teste e o que impede que volte a escorregar.
 */

const MODAL = readFileSync(
    new URL("../../src/Dashboard/components/modals/device.php", import.meta.url),
    "utf8",
);

function paneOf(id) {
    // O ficheiro e PHP, mas as duas paineis sao markup literal: recorta-se o separador
    // pedido e procura-se a linha dentro dele.
    const start = MODAL.indexOf(`id="${id}"`);
    assert.notEqual(start, -1, `o painel ${id} devia existir`);
    const next = MODAL.indexOf('class="tab-pane', start + 1);

    return MODAL.slice(start, next === -1 ? undefined : next);
}

test("o selector vive no separador das configuracoes e nao no geral", () => {
    assert.match(
        paneOf("deviceConfigPane"),
        /id="deviceDiaperSensitivityRow"/,
        "a sensibilidade e uma configuracao",
    );
    assert.doesNotMatch(
        paneOf("deviceGeneralPane"),
        /id="deviceDiaperSensitivityRow"/,
        "o separador geral e para identidade e autorizacao",
    );
});

test("o selector e irmao do painel de configuracoes e nao filho", () => {
    // O renderDeviceConfigurationModal reescreve o innerHTML do deviceConfigRoot a cada
    // abertura. Dentro dele, esta linha era apagada; ao lado, sobrevive -- e e por isso
    // que nao precisa de passar pelo catalogo de downlinks, que para este sensor e vazio.
    const pane = paneOf("deviceConfigPane");
    const row = pane.indexOf('id="deviceDiaperSensitivityRow"');
    const root = pane.indexOf('id="deviceConfigRoot"');

    assert.ok(row < root, "a linha vem antes do root");
    assert.doesNotMatch(
        pane.slice(root),
        /deviceDiaperSensitivityRow/,
        "a linha nao pode estar dentro do root",
    );
});

function harness() {
    const dom = new JSDOM(`<!doctype html><body>
        <div id="row" class="d-none">
            <select id="profile">
                <option value="more_alerts">Mais</option>
                <option value="normal">Normal</option>
                <option value="fewer_alerts">Menos</option>
                <option value="custom">Personalizado</option>
            </select>
            <div id="custom" class="d-none">
                <input type="number" id="range">
                <input type="number" id="value">
            </div>
        </div>
    </body>`);
    const d = dom.window.document;
    const els = {
        deviceDiaperSensitivityRow: d.getElementById("row"),
        deviceDiaperSensitivityProfile: d.getElementById("profile"),
        deviceDiaperSensitivityCustom: d.getElementById("custom"),
        deviceDiaperPollutionRange: d.getElementById("range"),
        deviceDiaperPollutionValue: d.getElementById("value"),
    };
    initDiaperSensitivityUi({els});

    return els;
}

test("nada e gravado antes de haver um sensor carregado", async () => {
    // Um dispositivo novo nao passou por um GET, portanto nao houve escolha nenhuma.
    // Devolver o preset por omissao mandava um DELETE inutil a cada gravacao.
    const els = harness();
    await loadDiaperSensitivity("", "diaper_sensor");

    assert.equal(selectedDiaperSensitivity("diaper_sensor"), null);
    assert.ok(els.deviceDiaperSensitivityRow.classList.contains("d-none") === false);
});

test("a linha desaparece quando o dispositivo nao e um medidor de fraldas", async () => {
    const els = harness();
    await loadDiaperSensitivity("fbd87c59ba8b", "bracelet");

    assert.ok(els.deviceDiaperSensitivityRow.classList.contains("d-none"));
    assert.equal(selectedDiaperSensitivity("bracelet"), null);
});
