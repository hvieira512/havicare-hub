import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { deviceTypeFields, deviceTypeOptions } from "../../src/Dashboard/dashboard/domain.js";

/** A forma de cada tipo prende-se no `DeviceTypeCatalogTest`; aqui só o que o JS acrescenta. */
/** O que se lê é português; o nome do campo no fio é que não muda. */
test("nenhum rótulo, ajuda ou placeholder do catálogo está em inglês", () => {
    for (const { value: type } of deviceTypeOptions) {
        const { identity } = deviceTypeFields(type);
        const text = [identity.label, identity.help, identity.placeholder].join(" ");

        assert.doesNotMatch(text, /\bdevice\b|\bID\b(?! do)|\baddress\b/i, `texto de ${type}`);
        assert.equal(identity.field === "imei" || identity.field === "deviceId", true);
    }
});

test("um tipo desconhecido cai no relogio, como o normalizeDeviceType", () => {
    // A tabela normaliza o tipo, para nenhum chamador ter de o fazer.
    assert.equal(deviceTypeFields("nao-existe").identity.field, "imei");
});

/** A pulseira PST fala pela cloud Tuya: o tipo é o mesmo das BLE, mas não tem MAC nem gateways. */
test("o protocolo corrige o que o tipo diz", () => {
    const tuya = deviceTypeFields("bracelet", "tuya-cloud");
    const ble = deviceTypeFields("bracelet", "moko-w6");

    assert.equal(tuya.gatewayLinks, false);
    assert.doesNotMatch(tuya.identity.label, /MAC/);
    assert.equal(tuya.identity.field, "deviceId");
    assert.equal(ble.gatewayLinks, true);
    assert.equal(ble.identity.label, "MAC");
});
