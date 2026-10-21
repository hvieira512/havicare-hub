import test from "node:test";
import assert from "node:assert/strict";

const { initSettingsCompanies, loadSettingsCompanySection } = await import(
    "../../src/Dashboard/dashboard/settings/companies.js",
);
const { invalidateLicenses } = await import("../../src/Dashboard/dashboard/licenses.js");

/**
 * Cada linha da árvore leva os seus verbos num menu e não em ícones soltos: três ícones sem
 * nome por linha não se adivinham, e o primeiro deles abre a cloud dos radares.
 */
const jsonResponse = (obj) => Response.json(obj);

const COMPANIES = [{ id: 7, name: "hitcare" }];
const LICENSES = [{ id: 31, license_id: 1001, company_id: 7, name: "gucc.dev" }];

function setupDom() {
    document.body.innerHTML = "<div id=\"summary\"></div><div id=\"body\"></div>" +
        "<div id=\"pager\"><div id=\"pagerSummary\"></div><ul id=\"pagerControls\"></ul></div>";
    const els = {
        companiesTabSummary: document.getElementById("summary"),
        companyListBody: document.getElementById("body"),
        settingsCompanyPagination: document.getElementById("pager"),
        settingsCompanyPaginationSummary: document.getElementById("pagerSummary"),
        settingsCompanyPaginationControls: document.getElementById("pagerControls"),
    };
    initSettingsCompanies({ els });
    invalidateLicenses();
    globalThis.fetch = async (url) => jsonResponse(
        String(url).includes("/api/licenses")
            ? { data: LICENSES }
            : { data: COMPANIES, pagination: null },
    );
    return els;
}

/** O menu de uma linha: o botão que o abre e os itens que ele contém. */
function menuOf(row) {
    const toggle = row.querySelector("[data-bs-toggle=\"dropdown\"]");
    const items = [...row.querySelectorAll(".dropdown-menu .dropdown-item")];
    return { toggle, items, actions: items.map((item) => item.dataset.action) };
}

test("a linha de uma licença junta os três verbos num menu só", async () => {
    const els = setupDom();
    await loadSettingsCompanySection();

    const row = els.companyListBody.querySelector(".tree-row");
    const { toggle, actions } = menuOf(row);

    assert.ok(toggle, "a linha da licença não tem botão de menu");
    assert.deepEqual(actions, ["editRadarCredentials", "editLicense", "deleteLicense"]);
    assert.ok(row.querySelector(".dropdown-divider"), "o apagar não está separado dos outros");
});

test("o botão do menu de uma licença diz no aria-label de qual é", async () => {
    const els = setupDom();
    await loadSettingsCompanySection();

    const { toggle } = menuOf(els.companyListBody.querySelector(".tree-row"));

    assert.match(toggle.getAttribute("aria-label"), /gucc\.dev/);
});

test("a linha de uma empresa junta os três verbos no mesmo menu", async () => {
    const els = setupDom();
    await loadSettingsCompanySection();

    const header = els.companyListBody.querySelector(".card-body > div");
    const { toggle, items, actions } = menuOf(header);

    assert.ok(toggle, "a linha da empresa não tem botão de menu");
    assert.match(toggle.getAttribute("aria-label"), /hitcare/);
    assert.deepEqual(actions, ["newLicenseForCompany", "editCompany", "deleteCompany"]);
    assert.deepEqual(
        items.map((item) => item.textContent.trim()),
        ["Nova licença", "Editar empresa", "Eliminar empresa"],
    );
});
