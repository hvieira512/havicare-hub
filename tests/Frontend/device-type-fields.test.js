import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import { deviceTypeFields, deviceTypeOptions } from "../../src/Dashboard/dashboard/domain.js";

/**
 * REDE DE SEGURANCA, escrita antes de tocar no modal.
 *
 * O `renderDeviceTypeSelector` decidia isto com quatro cadeias de `if` e cinco
 * `classList.toggle`. Estas expectativas são o comportamento que está em produção, e por
 * isso este ficheiro não muda quando a tabela de tipos passa a servi-lo: se for preciso mudar
 * uma expectativa aqui, a tabela mudou comportamento e não só a forma.
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

test("os outros tipos identificam-se por deviceId", () => {
    for (const type of TYPES.filter((t) => t !== "watch")) {
        assert.equal(deviceTypeFields(type).identity.field, "deviceId", type);
    }
});

/**
 * Identificar-se por MAC e levar SIM são duas perguntas distintas, e o gateway responde
 * diferente às duas: é o cartão dele que faz o backhaul. Enquanto foram a mesma pergunta --
 * um `deviceType !== "watch"` -- guardar um gateway apagava-lhe o número.
 */
test("o SIM não acompanha o tipo de identidade: o gateway tem os dois", () => {
    assert.equal(deviceTypeFields("gateway").identity.field, "deviceId");
    assert.equal(deviceTypeFields("gateway").sim, true);

    for (const type of ["ncs", "radar", "diaper_sensor", "bracelet"]) {
        assert.equal(deviceTypeFields(type).sim, false, type);
    }
});

test("só o medidor de fraldas e a pulseira ligam a gateways", () => {
    assert.equal(deviceTypeFields("diaper_sensor").gatewayLinks, true);
    assert.equal(deviceTypeFields("bracelet").gatewayLinks, true);
    for (const type of ["watch", "ncs", "radar", "gateway"]) {
        assert.equal(deviceTypeFields(type).gatewayLinks, false, type);
    }
});

test("o rótulo, a ajuda e o placeholder da identidade por tipo", () => {
    const expected = {
        watch: {
            label: "IMEI",
        },
        ncs: {
            label: "MAC",
            help: "Endereço MAC do dispositivo NCS, sem separadores (12 caracteres hexadecimais).",
            placeholder: "bea6c3dd8e02",
        },
        radar: {
            label: "ID do dispositivo",
            help: "Identificador do radar no protocolo.",
            placeholder: "414d74184cbf",
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

/** O que se lê é português; o nome do campo no fio é que não muda. */
test("nenhum rótulo, ajuda ou placeholder do catálogo está em inglês", () => {
    for (const { value: type } of deviceTypeOptions) {
        const { identity } = deviceTypeFields(type);
        const lido = [identity.label, identity.help, identity.placeholder].join(" ");

        assert.doesNotMatch(lido, /\bdevice\b|\bID\b(?! do)|\baddress\b/i, `texto de ${type}`);
        assert.equal(identity.field === "imei" || identity.field === "deviceId", true);
    }
});

test("um tipo desconhecido cai no relogio, como o normalizeDeviceType", () => {
    // O modal chamava sempre normalizeDeviceType antes de decidir, e a tabela mantem
    // esse contrato para nenhum chamador ter de o repetir.
    assert.equal(deviceTypeFields("nao-existe").identity.field, "imei");
});
