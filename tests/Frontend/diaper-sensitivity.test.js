import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import {JSDOM} from "jsdom";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {
    hasDiaperSensitivity,
    initDiaperSensitivityUi,
    loadDiaperSensitivity,
    selectedDiaperSensitivity,
} from "../../src/Dashboard/dashboard/devices/diaper-sensitivity-ui.js";
import {renderDeviceConfigurationRoot} from "../../src/Dashboard/dashboard/config.js";

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
            <div id="group">
                <button data-diaper-profile="fewer_alerts"></button>
                <button data-diaper-profile="normal"></button>
                <button data-diaper-profile="more_alerts"></button>
                <button data-diaper-profile="custom"></button>
            </div>
            <div id="custom" class="d-none">
                <input type="number" id="range">
                <input type="number" id="value">
            </div>
            <button id="save"></button>
            <span id="feedback"></span>
        </div>
    </body>`);
    const d = dom.window.document;
    const els = {
        deviceDiaperSensitivityRow: d.getElementById("row"),
        deviceDiaperSensitivityGroup: d.getElementById("group"),
        deviceDiaperSensitivityCustom: d.getElementById("custom"),
        deviceDiaperPollutionRange: d.getElementById("range"),
        deviceDiaperPollutionValue: d.getElementById("value"),
        deviceDiaperSensitivitySaveBtn: d.getElementById("save"),
        deviceDiaperSensitivityFeedback: d.getElementById("feedback"),
    };
    initDiaperSensitivityUi({els});

    return {els, document: d};
}

function click(element) {
    element.dispatchEvent(
        new element.ownerDocument.defaultView.MouseEvent("click", {bubbles: true}),
    );
}

function press(els, profile) {
    click(els.deviceDiaperSensitivityGroup.querySelector(`[data-diaper-profile="${profile}"]`));
}

test("os quatro perfis sao botoes e nao um select", () => {
    const pane = paneOf("deviceConfigPane");

    assert.doesNotMatch(pane, /<select/, "a forma e a do grupo de botoes dos relogios");
    for (const profile of ["fewer_alerts", "normal", "more_alerts", "custom"]) {
        assert.match(pane, new RegExp(`data-diaper-profile="${profile}"`));
    }
    // Menos sensivel em verde, normal em ambar, mais sensivel em vermelho, como na
    // sensibilidade de queda dos relogios.
    assert.match(pane, /btn-outline-success[\s\S]*?fewer_alerts/);
    assert.match(pane, /btn-outline-danger[\s\S]*?more_alerts/);
});

test("as etiquetas nomeiam a sensibilidade e nao a contagem de alertas", () => {
    // O controlo chama-se sensibilidade, portanto as opcoes tambem: "menos alertas"
    // descrevia a consequencia e obrigava a inverter, porque sensibilidade baixa da
    // menos alertas. Baixa/Normal/Alta e o que os relogios usam para a queda.
    const pane = paneOf("deviceConfigPane");

    assert.match(pane, /data-diaper-profile="fewer_alerts"[\s\S]*?>Baixa\s*</);
    assert.match(pane, /data-diaper-profile="normal"[\s\S]*?>Normal\s*</);
    assert.match(pane, /data-diaper-profile="more_alerts"[\s\S]*?>Alta\s*</);
    assert.doesNotMatch(pane, /Menos alertas|Mais alertas/);
});

test("nada e gravado antes de haver um sensor carregado", async () => {
    // Um dispositivo novo nao passou por um GET, portanto nao houve escolha nenhuma.
    // Devolver o preset por omissao mandava um DELETE inutil a cada gravacao.
    const {els} = harness();
    await loadDiaperSensitivity("", "diaper_sensor");

    assert.equal(selectedDiaperSensitivity("diaper_sensor"), null);
    assert.equal(hasDiaperSensitivity(), false);
    assert.equal(els.deviceDiaperSensitivityRow.classList.contains("d-none"), false);
});

test("a linha desaparece quando o dispositivo nao e um medidor de fraldas", async () => {
    const {els} = harness();
    await loadDiaperSensitivity("fbd87c59ba8b", "bracelet");

    assert.ok(els.deviceDiaperSensitivityRow.classList.contains("d-none"));
    assert.equal(selectedDiaperSensitivity("bracelet"), null);
});

test("premir personalizado revela os campos e mantem os valores", () => {
    const {els} = harness();
    els.deviceDiaperPollutionRange.value = "5";
    els.deviceDiaperPollutionValue.value = "9";

    press(els, "custom");

    assert.equal(els.deviceDiaperSensitivityCustom.classList.contains("d-none"), false);
    assert.equal(els.deviceDiaperPollutionRange.value, "5", "um preset escreveria por cima");
    assert.equal(
        els.deviceDiaperSensitivityGroup
            .querySelector('[data-diaper-profile="custom"]')
            .getAttribute("aria-pressed"),
        "true",
    );
});

test("o painel de baixo cala-se quando ha uma configuracao mostrada acima", () => {
    // "Este protocolo nao tem configuracoes suportadas" e verdade sobre downlinks e
    // mentira sobre o ecra, que tem a sensibilidade logo acima.
    const context = {protocol: "monit-mecs-pro-ble", catalog: []};

    assert.match(
        renderDeviceConfigurationRoot(context),
        /não tem configurações suportadas/,
    );
    assert.equal(
        renderDeviceConfigurationRoot({...context, quietWhenEmpty: true}),
        "",
    );
});

test("a linha tem o seu proprio botao de guardar", () => {
    // Sem ele era preciso voltar ao separador Geral para gravar, que e onde vive o
    // "Guardar dispositivo". As configuracoes dos relogios tambem gravam no seu lugar.
    const pane = paneOf("deviceConfigPane");

    assert.match(pane, /id="deviceDiaperSensitivitySaveBtn"/);
    // "Guardar" e nao "Enviar": nada e enviado ao sensor, que so transmite.
    assert.match(pane, /deviceDiaperSensitivitySaveBtn[\s\S]*?>Guardar\s*</);
    assert.doesNotMatch(pane, /deviceDiaperSensitivitySaveBtn[\s\S]*?>Enviar\s*</);
});

test("guardar antes de o dispositivo existir explica-se em vez de falhar calado", async () => {
    const {els} = harness();
    await loadDiaperSensitivity("", "diaper_sensor");

    click(els.deviceDiaperSensitivitySaveBtn);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.match(els.deviceDiaperSensitivityFeedback.textContent, /Guarde o dispositivo/);
    assert.match(els.deviceDiaperSensitivityFeedback.className, /text-danger/);
});

test("mudar de perfil limpa a mensagem anterior", async () => {
    const {els} = harness();
    await loadDiaperSensitivity("", "diaper_sensor");
    click(els.deviceDiaperSensitivitySaveBtn);
    await new Promise((resolve) => setTimeout(resolve, 0));

    press(els, "custom");

    assert.equal(els.deviceDiaperSensitivityFeedback.textContent, "");
});
