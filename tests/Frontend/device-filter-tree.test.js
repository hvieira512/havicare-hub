import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { renderDeviceFilterControls } = await import("../../src/Dashboard/dashboard/devices/list-filters.js");

/** As duas árvores do painel de filtros: o pai, e os filhos pendurados num ramo por baixo dele. */
let els;

const options = (root) => [...root.querySelectorAll(".filter-option")].map((button) => ({
    value: button.dataset.filterValue,
    nested: button.classList.contains("filter-option-nested"),
    inBranch: button.parentElement.classList.contains("filter-branch"),
    pressed: button.getAttribute("aria-pressed"),
    partial: button.classList.contains("partial"),
    count: button.querySelector(".count-number")?.textContent ?? null,
}));

beforeEach(() => {
    els = autoEls();
    cacheElements(els);
    state.licenses = [];
    state.deviceFilters = { deviceType: [], supplier: [], model: ["D41"], license: ["hitcare:1001"], online: null };
    state.summary.deviceFilterCounts = {
        deviceType: [],
        supplierModels: {
            suppliers: [{ supplier: "Wonlex", count: 3, models: [{ model: "D41", count: 2 }, { model: "MF91", count: 1 }] }],
        },
        license: { none: 4, companies: [{ company: "hitcare", count: 5, licenses: [{ licenseId: "1001", count: 5 }] }] },
    };
});

test("a supplier with one model ticked is half-ticked, and its models hang in a branch", () => {
    renderDeviceFilterControls();

    assert.deepEqual(options(els.deviceSupplierFilter), [
        { value: "Wonlex", nested: false, inBranch: false, pressed: "false", partial: true, count: "3" },
        { value: "D41", nested: true, inBranch: true, pressed: "true", partial: false, count: "2" },
        { value: "MF91", nested: true, inBranch: true, pressed: "false", partial: false, count: "1" },
    ]);
});

test("the licence tree puts «sem licença» first, with no branch", () => {
    renderDeviceFilterControls();

    assert.deepEqual(options(els.deviceLicenseFilter).map(({ value, inBranch, pressed, partial }) => ({ value, inBranch, pressed, partial })), [
        { value: "none", inBranch: false, pressed: "false", partial: false },
        { value: "hitcare", inBranch: false, pressed: "false", partial: true },
        { value: "hitcare:1001", inBranch: true, pressed: "true", partial: false },
    ]);
});

test("an empty tree says there is nothing to show", () => {
    state.summary.deviceFilterCounts.supplierModels = { suppliers: [] };

    renderDeviceFilterControls();

    assert.equal(els.deviceSupplierFilter.textContent.trim(), "Não há fornecedores para mostrar.");
});
