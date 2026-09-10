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

test("sem dono, di-lo e não inventa linhas", () => {
    for (const orfao of [
        { company: "null", licenseId: 0, licenseName: null },
        { company: "", licenseId: 0 },
    ]) {
        const markup = deviceLicenseBlock(orfao);

        assert.match(markup, /Sem licença/);
        assert.doesNotMatch(markup, /device-card-field-note/);
    }
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
