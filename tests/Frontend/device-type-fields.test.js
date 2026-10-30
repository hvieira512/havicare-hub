import test from "node:test";
import assert from "node:assert/strict";

import { deviceTypeFields, deviceTypeOptions } from "../../src/Dashboard/dashboard/core/domain.js";

/** A forma de cada tipo prende-se no `DeviceTypeCatalogTest`; aqui só o que o JS acrescenta. */
/** O que se lê é português; o nome do campo no fio é que não muda. */
test("no catalog label, help or placeholder is in English", () => {
    for (const { value: type } of deviceTypeOptions) {
        const { identity } = deviceTypeFields(type);
        const text = [identity.label, identity.help, identity.placeholder].join(" ");

        assert.doesNotMatch(text, /\bdevice\b|\bID\b(?! do)|\baddress\b/i, `texto de ${type}`);
        assert.equal(identity.field === "imei" || identity.field === "deviceId", true);
    }
});

test("an unknown type falls back to the watch, like normalizeDeviceType", () => {
    // A tabela normaliza o tipo, para nenhum chamador ter de o fazer.
    assert.equal(deviceTypeFields("nao-existe").identity.field, "imei");
});
