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
