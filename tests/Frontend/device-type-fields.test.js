import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {deviceTypeFields} from "../../src/Dashboard/dashboard/domain.js";

/**
 * REDE DE SEGURANCA, escrita antes de tocar no modal.
 *
 * O `renderDeviceTypeSelector` decidia isto com quatro cadeias de `if` e cinco
 * `classList.toggle`, num modulo de 769 linhas sem um unico teste. Estas expectativas
 * sao o comportamento que estava em producao, copiado de la sem alteracoes -- e por
 * isso este ficheiro nao muda quando a tabela de tipos passa a servi-lo. Se for preciso
 * mudar uma expectativa aqui, a tabela mudou comportamento e nao so a forma.
 *
 * O que se fixa: que campos aparecem por tipo, e que rotulo, ajuda e placeholder tem o
 * campo de identidade -- que era a parte espalhada por mais sitios.
 */

const TYPES = ["watch", "ncs", "radar", "gateway", "diaper_sensor", "bracelet"];

test("todos os tipos de dispositivo tem uma linha na tabela", () => {
    for (const type of TYPES) {
        assert.ok(deviceTypeFields(type), `falta o tipo ${type}`);
    }
});

test("o relogio identifica-se por IMEI e tem SIM", () => {
    const fields = deviceTypeFields("watch");

    assert.equal(fields.identity.field, "imei");
    assert.equal(fields.sim, true);
    assert.equal(fields.gatewayLinks, false);
});

test("os outros tipos identificam-se por deviceId e nao tem SIM", () => {
    for (const type of TYPES.filter((t) => t !== "watch")) {
        const fields = deviceTypeFields(type);
        assert.equal(fields.identity.field, "deviceId", type);
        assert.equal(fields.sim, false, type);
    }
});

test("so o medidor de fraldas e a pulseira ligam a gateways", () => {
    assert.equal(deviceTypeFields("diaper_sensor").gatewayLinks, true);
    assert.equal(deviceTypeFields("bracelet").gatewayLinks, true);
    for (const type of ["watch", "ncs", "radar", "gateway"]) {
        assert.equal(deviceTypeFields(type).gatewayLinks, false, type);
    }
});

test("o rotulo, a ajuda e o placeholder da identidade por tipo", () => {
    // Copiado literalmente do renderDeviceTypeSelector antes da mudanca.
    const expected = {
        watch: {
            label: "IMEI",
        },
        ncs: {
            label: "Device ID (MAC)",
            help: "MAC address do dispositivo NCS (ex.: bea6c3dd8e02). Obrigatório.",
            placeholder: "MAC address (ex.: bea6c3dd8e02)",
        },
        radar: {
            label: "Device ID",
            help: "Identificador do dispositivo radar no protocolo.",
            placeholder: "ID do dispositivo",
        },
        gateway: {
            label: "MAC",
            help: "Endereço MAC canónico, sem separadores (12 caracteres hexadecimais).",
            placeholder: "d48c49f7909c",
        },
        diaper_sensor: {
            label: "MAC",
            help: "Endereço MAC canónico, sem separadores (12 caracteres hexadecimais).",
            placeholder: "d48c49f7909c",
        },
        bracelet: {
            label: "MAC",
            help: "Endereço MAC canónico, sem separadores (12 caracteres hexadecimais).",
            placeholder: "d48c49f7909c",
        },
    };

    for (const [type, wanted] of Object.entries(expected)) {
        const identity = deviceTypeFields(type).identity;
        assert.equal(identity.label, wanted.label, `rótulo de ${type}`);
        if (wanted.help) {
            assert.equal(identity.help, wanted.help, `ajuda de ${type}`);
            assert.equal(identity.placeholder, wanted.placeholder, `placeholder de ${type}`);
        }
    }
});

test("so o medidor de fraldas tem regras do hub, e e a sensibilidade", () => {
    assert.deepEqual(deviceTypeFields("diaper_sensor").hubRules, ["diaper_sensitivity"]);
    for (const type of TYPES.filter((t) => t !== "diaper_sensor")) {
        assert.deepEqual(deviceTypeFields(type).hubRules, [], type);
    }
});

test("um tipo desconhecido cai no relogio, como o normalizeDeviceType", () => {
    // O modal chamava sempre normalizeDeviceType antes de decidir, e a tabela mantem
    // esse contrato para nenhum chamador ter de o repetir.
    assert.equal(deviceTypeFields("nao-existe").identity.field, "imei");
});
