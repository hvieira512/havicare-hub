import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { deviceLicenseBlock as buildBlock } from "../../src/Dashboard/dashboard/components/device-license.js";

// O construtor devolve um fragmento de marcação; as assertivas de texto querem texto.
const deviceLicenseBlock = (...args) => String(buildBlock(...args));

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
    const markup = deviceLicenseBlock({ ...device, company: "havicare", licenseId: 2107 });

    assert.match(markup, /havicare/);
    assert.match(markup, /2107/);
    assert.doesNotMatch(markup, /hitcare/);
});

// Os casos vêm do inventário: 21 dispositivos com empresa e licença, 4 sem nenhuma das duas,
// e um com a empresa gravada como a string "null" mas com licença 1001.
test("sem dono, di-lo e não inventa linhas", () => {
    for (const orphan of [
        { company: "null", licenseId: 0, licenseName: null },
        { company: "", licenseId: 0 },
        { company: null, licenseId: null },
        { company: "null", licenseId: 1001 },
    ]) {
        const markup = deviceLicenseBlock(orphan);

        assert.match(markup, /Sem licença/);
        assert.doesNotMatch(markup, /license-number/);
    }
});

// A licença chega da API como texto, e a comparação com zero tem de o aceitar.
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
