import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import {JSDOM} from "jsdom";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {
    hasHubRules,
    renderHubRules,
    ruleFor,
    selectHubRuleValue,
} from "../../src/Dashboard/dashboard/devices/hub-rules/index.js";
import {renderDeviceConfigurationRoot} from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * As regras do hub: configuracoes que nao viajam para o dispositivo.
 *
 * A versao anterior desta feature tinha-as num separador proprio, "Regras do hub", ao lado
 * de "Configuracoes". Uma revisao apanhou que isso nomeava a implementacao -- ambas sao
 * configuracao, e o que difere e se a alteracao tem de viajar. Estes testes prendem o
 * desenho corrigido: um separador, e a diferenca no bloco.
 */

const MODAL = readFileSync(
    new URL("../../src/Dashboard/components/modals/device.php", import.meta.url),
    "utf8",
);

const SENSITIVITY = {
    pollutionRange: 4,
    pollutionValue: 12,
    profile: "normal",
    presets: {
        low: {pollutionRange: 7, pollutionValue: 15},
        normal: {pollutionRange: 4, pollutionValue: 12},
        high: {pollutionRange: 3, pollutionValue: 7},
    },
    bounds: {pollutionRange: [2, 10], pollutionValue: [5, 25]},
};

function blockFor(deviceType, data = SENSITIVITY, feedback = {}) {
    const html = renderHubRules(deviceType, {diaper_sensitivity: data}, feedback);
    const dom = new JSDOM(`<!doctype html><body><div id="root">${html}</div></body>`);

    return {
        root: dom.window.document.getElementById("root"),
        block: dom.window.document.querySelector("[data-hub-rule]"),
        html,
    };
}

test("nao ha um separador de regras do hub no markup", () => {
    // O separador proprio era o erro. Sao dois separadores e nao tres.
    assert.doesNotMatch(MODAL, /Regras do hub/);
    assert.doesNotMatch(MODAL, /deviceHubRulesTabBtn/);
    // E a marcacao estatica da sensibilidade saiu: agora e desenhada no painel.
    assert.doesNotMatch(MODAL, /deviceDiaperSensitivityRow/);
});

test("so o medidor de fraldas tem regras do hub", () => {
    assert.equal(hasHubRules("diaper_sensor"), true);
    for (const type of ["watch", "ncs", "radar", "gateway", "bracelet"]) {
        assert.equal(hasHubRules(type), false, type);
    }
});

test("um tipo sem regras nao desenha bloco nenhum", () => {
    assert.equal(renderHubRules("watch", {}), "");
});

test("o bloco diz que e aplicada no hub e o botao diz Guardar", () => {
    // O que o distingue de uma configuracao que viaja: sem "Enviado", sem "A espera",
    // sem data de entrega. Nao ha bytes a caminho de nada.
    const {html} = blockFor("diaper_sensor");

    assert.match(html, /Aplicada no hub/);
    assert.match(html, />Guardar\s*</);
    assert.doesNotMatch(html, /Enviar|Enviado|À espera|Confirmado/);
});

test("os quatro niveis sao botoes, do menos sensivel para o mais", () => {
    const {block} = blockFor("diaper_sensor");
    const order = [...block.querySelectorAll("[data-hub-rule-value]")].map(
        (button) => button.dataset.hubRuleValue,
    );

    assert.deepEqual(order, ["low", "normal", "high", "custom"]);
});

test("as etiquetas nomeiam a sensibilidade e nao a contagem de alertas", () => {
    const {html} = blockFor("diaper_sensor");

    assert.match(html, /data-hub-rule-value="low"[\s\S]*?>Baixa\s*</);
    assert.match(html, /data-hub-rule-value="high"[\s\S]*?>Alta\s*</);
    assert.doesNotMatch(html, /Menos alertas|Mais alertas/);
});

test("o perfil em vigor vem marcado", () => {
    const {block} = blockFor("diaper_sensor", {...SENSITIVITY, profile: "high"});
    const active = block.querySelector("[data-hub-rule-value].active");

    assert.equal(active.dataset.hubRuleValue, "high");
    assert.equal(active.getAttribute("aria-pressed"), "true");
});

test("os campos personalizados so aparecem no perfil personalizado", () => {
    assert.ok(
        blockFor("diaper_sensor").block
            .querySelector("[data-hub-rule-custom]")
            .classList.contains("d-none"),
    );
    assert.equal(
        blockFor("diaper_sensor", {...SENSITIVITY, profile: "custom"}).block
            .querySelector("[data-hub-rule-custom]")
            .classList.contains("d-none"),
        false,
    );
});

test("as gamas do servidor entram nos campos", () => {
    // Sem isto o ecra mantinha uma segunda copia destas fronteiras.
    const {block} = blockFor("diaper_sensor", {...SENSITIVITY, profile: "custom"});
    const range = block.querySelector('[data-hub-rule-field="pollutionRange"]');

    assert.equal(range.getAttribute("min"), "2");
    assert.equal(range.getAttribute("max"), "10");
});

test("escolher um nivel marca-o e revela ou esconde os campos", () => {
    const {block} = blockFor("diaper_sensor");

    selectHubRuleValue(block, "custom");
    assert.equal(
        block.querySelector("[data-hub-rule-custom]").classList.contains("d-none"),
        false,
    );

    selectHubRuleValue(block, "low");
    assert.equal(block.querySelector("[data-hub-rule-value].active").dataset.hubRuleValue, "low");
    assert.ok(block.querySelector("[data-hub-rule-custom]").classList.contains("d-none"));
});

test("um preset e lido do preset e nao dos campos", () => {
    const rule = ruleFor("diaper_sensitivity");
    const {block} = blockFor("diaper_sensor");
    selectHubRuleValue(block, "high");

    assert.deepEqual(rule.read(block, SENSITIVITY), {
        profile: "high",
        pollutionRange: 3,
        pollutionValue: 7,
    });
});

test("um par personalizado e lido dos campos", () => {
    const rule = ruleFor("diaper_sensitivity");
    const {block} = blockFor("diaper_sensor", {...SENSITIVITY, profile: "custom"});
    block.querySelector('[data-hub-rule-field="pollutionRange"]').value = "5";
    block.querySelector('[data-hub-rule-field="pollutionValue"]').value = "9";

    assert.deepEqual(rule.read(block, SENSITIVITY), {
        profile: "custom",
        pollutionRange: 5,
        pollutionValue: 9,
    });
});

test("um par que nao sejam dois inteiros e recusado antes de sair do browser", () => {
    // O input de numero aceita vazio e aceita "4e2", e isto decide alarmes de muda.
    const rule = ruleFor("diaper_sensitivity");

    assert.equal(rule.validate({pollutionRange: 4, pollutionValue: 12}), null);
    assert.notEqual(rule.validate({pollutionRange: NaN, pollutionValue: 12}), null);
    assert.notEqual(rule.validate({pollutionRange: 4.5, pollutionValue: 12}), null);
});

test("repor poe no normal", () => {
    assert.equal(ruleFor("diaper_sensitivity").resetProfile, "normal");
});

test("o resultado sai na caixa de aviso das configuracoes dos relogios", () => {
    const {html} = blockFor("diaper_sensor", SENSITIVITY, {
        diaper_sensitivity: {message: "Guardado.", tone: "success"},
    });

    assert.match(html, /alert alert-success/);
    assert.match(html, /Guardado\./);
});

test("o painel so se diz vazio quando nao ha downlinks NEM regras do hub", () => {
    const context = {protocol: "monit-mecs-pro-ble", catalog: []};

    assert.match(
        renderDeviceConfigurationRoot(context),
        /não tem configurações suportadas/,
        "um protocolo sem nada diz que nao tem",
    );
    assert.equal(
        renderDeviceConfigurationRoot({...context, quietWhenEmpty: true}),
        "",
        "com uma regra do hub acima, cala-se",
    );
});
