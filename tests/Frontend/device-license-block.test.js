import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { deviceLicenseBlock } from "../../src/Dashboard/dashboard/components/device-license.js";

/** A empresa fica na segunda linha: o mesmo sítio pode ter licença em duas empresas. */
const device = {
    company: "hitcare",
    licenseId: 2103,
    licenseName: "gerpi1.casabrancaresidencial",
};

test("o nome vem primeiro, e a empresa com o número por baixo", () => {
    const markup = deviceLicenseBlock(device);

    assert.match(markup, /gerpi1\.casabrancaresidencial/);
    assert.match(markup, /hitcare/);
    assert.match(markup, /2103/);
    assert.ok(
        markup.indexOf("gerpi1") < markup.indexOf("hitcare"),
        "o nome tem de vir antes do dono",
    );
});

test("duas licenças com o mesmo nome distinguem-se pela empresa", () => {
    const naHitcare = deviceLicenseBlock(device);
    const naHavicare = deviceLicenseBlock({ ...device, company: "havicare", licenseId: 2107 });

    assert.notEqual(naHitcare, naHavicare);
});

test("a empresa e o número ficam na mesma linha, separados", () => {
    const markup = deviceLicenseBlock(device);

    assert.match(markup, /license-separator/);
    assert.match(markup, /<span class="license-number">2103<\/span>/);
});

// Os casos vêm do inventário: 21 dispositivos com empresa e licença, 4 sem nenhuma das duas,
// e um com a empresa gravada como a string "null" mas com licença 1001.
test("sem dono, di-lo e não inventa linhas", () => {
    for (const orfao of [
        { company: "null", licenseId: 0, licenseName: null },
        { company: "", licenseId: 0 },
        { company: null, licenseId: null },
        { company: "null", licenseId: 1001 },
    ]) {
        const markup = deviceLicenseBlock(orfao);

        assert.match(markup, /Sem licença/);
        assert.doesNotMatch(markup, /license-number/);
    }
});

// A licença chega como texto da API e o normalizador devolve texto: comparada com o número 0
// nunca era igual, e o campo mostrava "empresa · 0" em vez de "Sem licença".
test("a licença zero conta como sem licença mesmo com empresa preenchida", () => {
    assert.match(deviceLicenseBlock({ company: "hitcare", licenseId: "0" }), /Sem licença/);
    assert.match(deviceLicenseBlock({ company: "hitcare", licenseId: 0 }), /Sem licença/);
});

test("sem nome, a empresa sobe para a primeira linha", () => {
    const markup = deviceLicenseBlock({ company: "hitcare", licenseId: 2103, licenseName: "" });

    assert.match(markup, /hitcare/);
    assert.match(markup, /2103/);
    assert.doesNotMatch(markup, /undefined|null/);
});

test("o que vem do servidor sai inerte", () => {
    const markup = deviceLicenseBlock({
        company: "hitcare",
        licenseId: 1,
        licenseName: "<img src=x onerror=alert(1)>",
    });

    assert.doesNotMatch(markup, /<img/);
});

/**
 * O cartão trunca numa coluna estreita; o painel de factos tem a largura do cartão e quebra.
 * As classes são de quem chama, e não deste módulo.
 */
test("as classes vêm de quem chama, para o cartão e o painel de detalhe usarem o mesmo bloco", () => {
    const noCartao = deviceLicenseBlock(device, {
        valueClass: "device-card-field-value",
        noteClass: "device-card-field-note text-truncate",
    });
    const noDetalhe = deviceLicenseBlock(device);

    assert.match(noCartao, /class="device-card-field-value"/);
    assert.match(noCartao, /class="device-card-field-note text-truncate"/);
    assert.doesNotMatch(noDetalhe, /device-card-/);
});

test("sem classes o bloco continua a ser duas linhas", () => {
    const markup = deviceLicenseBlock(device);

    assert.match(markup, /gerpi1\.casabrancaresidencial/);
    assert.match(markup, /hitcare/);
    assert.match(markup, /2103/);
});

test("sem licença aceita a classe de quem chama, para o vazio não desalinhar", () => {
    const markup = deviceLicenseBlock(
        { company: "", licenseId: 0 },
        { valueClass: "device-card-field-value" },
    );

    assert.match(markup, /class="device-card-field-value text-body-secondary">Sem licença</);
});
