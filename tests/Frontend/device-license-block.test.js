import test from "node:test";
import assert from "node:assert/strict";

import { deviceLicenseBlock as buildBlock } from "../../src/Dashboard/dashboard/devices/device-license.js";
import { asText } from "./support/dom.js";

const deviceLicenseBlock = asText(buildBlock);

/** A empresa fica na segunda linha: o mesmo sítio pode ter licença em duas empresas. */
const device = {
    company: "hitcare",
    licenseId: 2103,
    licenseName: "gerpi1.casabrancaresidencial",
};

test("the name comes first, with the company and number below", () => {
    const markup = deviceLicenseBlock(device);

    assert.match(markup, /gerpi1\.casabrancaresidencial/);
    assert.match(markup, /hitcare/);
    assert.match(markup, /2103/);
    assert.ok(
        markup.indexOf("gerpi1") < markup.indexOf("hitcare"),
        "o nome tem de vir antes do dono",
    );
});

test("two licences with the same name are told apart by the company", () => {
    const markup = deviceLicenseBlock({ ...device, company: "havicare", licenseId: 2107 });

    assert.match(markup, /havicare/);
    assert.match(markup, /2107/);
    assert.doesNotMatch(markup, /hitcare/);
});

test("without an owner it says so and invents no lines", () => {
    const markup = deviceLicenseBlock({ company: null, licenseId: null, licenseName: null });

    assert.match(markup, /Sem licença/);
    assert.doesNotMatch(markup, /license-number/);
});

test("without a name the company moves up to the first line", () => {
    const markup = deviceLicenseBlock({ company: "hitcare", licenseId: 2103, licenseName: "" });

    assert.match(markup, /hitcare/);
    assert.match(markup, /2103/);
    assert.doesNotMatch(markup, /undefined|null/);
});

test("what comes from the server comes out inert", () => {
    const markup = deviceLicenseBlock({
        company: "hitcare",
        licenseId: 1,
        licenseName: "<img src=x onerror=alert(1)>",
    });

    assert.doesNotMatch(markup, /<img/);
});
