import test from "node:test";
import assert from "node:assert/strict";

import {parseFragment} from "./support/dom.js";
import {
    licenseBadgeValue,
    licensePickerHtml,
    licenseTree,
} from "../../src/Dashboard/dashboard/devices/classification-ui.js";

/**
 * O selector de licenca: a arvore por onde se escolhe o dono de um dispositivo.
 *
 * As linhas vem da `/api/licenses` tal como ela as devolve -- ja ordenadas por empresa e
 * com o nome dela em cada uma.
 */
const ROWS = [
    {id: 1, company_id: 2, company_name: "havicare", license_id: 1, name: "hc.dev"},
    {id: 2, company_id: 2, company_name: "havicare", license_id: 2, name: ""},
    {id: 3, company_id: 1, company_name: "hitcare", license_id: 1001, name: "gucc.dev"},
];

test("as licencas agrupam-se pela empresa que as detem", () => {
    assert.deepEqual(licenseTree(ROWS), [
        {
            company: "havicare",
            licenses: [
                {licenseId: "1", name: "hc.dev"},
                {licenseId: "2", name: ""},
            ],
        },
        {company: "hitcare", licenses: [{licenseId: "1001", name: "gucc.dev"}]},
    ]);
});

test('"Sem licença" é a primeira linha, e é a única fora de uma empresa', () => {
    const root = parseFragment(licensePickerHtml(licenseTree(ROWS)));

    const options = [...root.querySelectorAll("[data-license-pick]")];
    assert.equal(options[0].textContent.trim(), "Sem licença");
    assert.equal(options[0].dataset.licenseCompany, "");
    assert.equal(options[0].closest(".filter-branch"), null);
    // As outras estão todas dentro do ramo da sua empresa.
    for (const option of options.slice(1)) {
        assert.notEqual(option.closest(".filter-branch"), null);
    }
});

test("a empresa é um cabeçalho e não se escolhe", () => {
    const root = parseFragment(licensePickerHtml(licenseTree(ROWS)));

    assert.deepEqual(
        [...root.querySelectorAll(".license-picker-company")].map((el) => el.textContent),
        ["havicare", "hitcare"],
    );
    // Nenhum cabeçalho é clicável: a empresa vem da licença que se escolher.
    assert.equal(root.querySelector(".license-picker-company[data-license-pick]"), null);
});

test("é de escolha única, e só a escolhida fica marcada", () => {
    const root = parseFragment(
        licensePickerHtml(licenseTree(ROWS), {company: "hitcare", licenseId: "1001"}),
    );

    assert.equal(root.querySelector('[role="radiogroup"]') !== null, true);
    const checked = [...root.querySelectorAll('[aria-checked="true"]')];
    assert.equal(checked.length, 1);
    assert.equal(checked[0].dataset.licenseId, "1001");
    assert.equal(checked[0].dataset.licenseCompany, "hitcare");
});

test("duas empresas podem ter a mesma licença, e a escolha distingue-as", () => {
    // `uq_licenses_company_license` é por empresa: o `license_id` sozinho não identifica.
    const shared = [
        {company_name: "havicare", license_id: 1, name: "hc.dev"},
        {company_name: "hitcare", license_id: 1, name: "gucc.dev"},
    ];
    const root = parseFragment(
        licensePickerHtml(licenseTree(shared), {company: "hitcare", licenseId: "1"}),
    );

    const checked = [...root.querySelectorAll('[aria-checked="true"]')];
    assert.equal(checked.length, 1);
    assert.equal(checked[0].dataset.licenseCompany, "hitcare");
});

test("a badge da trilha diz a licença por palavras", () => {
    const tree = licenseTree(ROWS);
    assert.equal(licenseBadgeValue(null, tree), "Sem licença");
    assert.equal(licenseBadgeValue({company: "", licenseId: "0"}, tree), "Sem licença");
    assert.equal(
        licenseBadgeValue({company: "hitcare", licenseId: "1001"}, tree),
        "gucc.dev (1001)",
    );
    // Sem nome, o número é tudo o que há para mostrar.
    assert.equal(licenseBadgeValue({company: "havicare", licenseId: "2"}, tree), "2");
});
