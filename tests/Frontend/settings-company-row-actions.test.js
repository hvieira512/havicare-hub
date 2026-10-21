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
const LICENSES = [{ id: 31, licenseId: 1001, companyId: 7, name: "gucc.dev" }];

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

test("a licence row gathers its three verbs in one menu", async () => {
    const els = setupDom();
    await loadSettingsCompanySection();

    const row = els.companyListBody.querySelector(".tree-row");
    const { toggle, actions } = menuOf(row);

    assert.ok(toggle, "a linha da licença não tem botão de menu");
    assert.deepEqual(actions, ["editRadarCredentials", "editLicense", "deleteLicense"]);
    assert.ok(row.querySelector(".dropdown-divider"), "o apagar não está separado dos outros");
});

test("a licence menu button names its licence in aria-label", async () => {
    const els = setupDom();
    await loadSettingsCompanySection();

    const { toggle } = menuOf(els.companyListBody.querySelector(".tree-row"));

    assert.match(toggle.getAttribute("aria-label"), /gucc\.dev/);
});

test("a company row gathers its three verbs in the same menu", async () => {
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

test("a company list that fails to load says so instead of counting zero", async () => {
    const els = setupDom();
    globalThis.fetch = async (url) => (String(url).includes("/api/licenses")
        ? jsonResponse({ data: LICENSES })
        : Response.json({ error: { code: "internal_error" } }, { status: 500 }));

    await loadSettingsCompanySection();

    assert.doesNotMatch(els.companiesTabSummary.textContent, /0 empresas/);
    assert.match(els.companyListBody.textContent, /Não foi possível carregar as empresas/);
});
