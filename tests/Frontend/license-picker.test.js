import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import {
    licenseBadgeValue,
    licensePickerHtml,
    licenseTree,
} from "../../src/Dashboard/dashboard/devices/classification-ui.js";

/**
 * O selector de licença: as linhas vêm da `/api/licenses` já ordenadas por empresa e com o
 * nome dela em cada uma.
 */
const ROWS = [
    { id: 1, company_id: 2, company_name: "havicare", license_id: 1, name: "hc.dev" },
    { id: 2, company_id: 2, company_name: "havicare", license_id: 2, name: "" },
    { id: 3, company_id: 1, company_name: "hitcare", license_id: 1001, name: "gucc.dev" },
];

test("licences group under the company that owns them", () => {
    assert.deepEqual(licenseTree(ROWS), [
        {
            company: "havicare",
            licenses: [
                { licenseId: "1", name: "hc.dev" },
                { licenseId: "2", name: "" },
            ],
        },
        { company: "hitcare", licenses: [{ licenseId: "1001", name: "gucc.dev" }] },
    ]);
});

test("the company is a header and cannot be picked", () => {
    const root = parseFragment(licensePickerHtml(licenseTree(ROWS)));

    assert.deepEqual(
        [...root.querySelectorAll(".license-picker-company")].map((el) => el.textContent),
        ["havicare", "hitcare"],
    );
    // Nenhum cabeçalho é clicável: a empresa vem da licença que se escolher.
    assert.equal(root.querySelector(".license-picker-company[data-license-pick]"), null);
});

test("it is single choice, and only the chosen one is marked", () => {
    const root = parseFragment(
        licensePickerHtml(licenseTree(ROWS), { company: "hitcare", licenseId: "1001" }),
    );

    assert.equal(root.querySelector("[role=\"radiogroup\"]") !== null, true);
    const checked = [...root.querySelectorAll("[aria-checked=\"true\"]")];
    assert.equal(checked.length, 1);
    assert.equal(checked[0].dataset.licenseId, "1001");
    assert.equal(checked[0].dataset.licenseCompany, "hitcare");
});

test("two companies can share a licence number, and the choice tells them apart", () => {
    // `uq_licenses_company_license` é por empresa: o `license_id` sozinho não identifica.
    const shared = [
        { company_name: "havicare", license_id: 1, name: "hc.dev" },
        { company_name: "hitcare", license_id: 1, name: "gucc.dev" },
    ];
    const root = parseFragment(
        licensePickerHtml(licenseTree(shared), { company: "hitcare", licenseId: "1" }),
    );

    const checked = [...root.querySelectorAll("[aria-checked=\"true\"]")];
    assert.equal(checked.length, 1);
    assert.equal(checked[0].dataset.licenseCompany, "hitcare");
});

test("the trail badge names the licence in words", () => {
    const tree = licenseTree(ROWS);
    assert.equal(licenseBadgeValue(null, tree), "Sem licença");
    assert.equal(licenseBadgeValue({ company: "", licenseId: "0" }, tree), "Sem licença");
    assert.equal(
        licenseBadgeValue({ company: "hitcare", licenseId: "1001" }, tree),
        "gucc.dev (1001)",
    );
    // Sem nome, o número é tudo o que há para mostrar.
    assert.equal(licenseBadgeValue({ company: "havicare", licenseId: "2" }, tree), "2");
});
