import test from "node:test";
import assert from "node:assert/strict";

import {deviceLicenseHtml} from "../../src/Dashboard/dashboard/widgets.js";

// Os casos vêm do inventário: 21 dispositivos com empresa e licença, 4 sem nenhuma das
// duas, e um com a empresa gravada como a string "null" mas com licença 1001.
test("a empresa e a licença aparecem no mesmo valor", () => {
    const html = deviceLicenseHtml({company: "hitcare", licenseId: 1001});

    assert.match(html, /hitcare/);
    assert.match(html, /license-separator/);
    assert.match(html, /<span class="license-number">1001<\/span>/);
});

test("sem empresa nem licença é um valor só, e não dois campos vazios", () => {
    for (const device of [
        {company: "", licenseId: 0},
        {company: null, licenseId: null},
        {company: "null", licenseId: 1001},
    ]) {
        assert.match(deviceLicenseHtml(device), /license-empty">Sem licença</);
    }
});

// A licença chega como texto da API e o normalizador devolve texto, por isso o zero
// tem de ser comparado como "0" -- comparado com o número 0 nunca era igual e o campo
// mostrava "empresa · 0" em vez de "Sem licença".
test("a licença zero conta como sem licença mesmo com empresa preenchida", () => {
    assert.match(deviceLicenseHtml({company: "hitcare", licenseId: "0"}), /Sem licença/);
    assert.match(deviceLicenseHtml({company: "hitcare", licenseId: 0}), /Sem licença/);
});

test("o cartão da listagem leva a sua classe de valor, o painel de factos não", () => {
    assert.match(
        deviceLicenseHtml({company: "hitcare", licenseId: 1}, "device-card-field-value"),
        /^<span class="device-card-field-value">/,
    );
    assert.match(deviceLicenseHtml({company: "hitcare", licenseId: 1}), /^<span>hitcare/);
});
