import test from "node:test";
import assert from "node:assert/strict";

// Must come before the dashboard modules: they touch window while loading.
import "./support/browser-env.js";
import {
    appendPhoneListRow,
    removeConfigRow,
} from "../../src/Dashboard/dashboard/config/row-editing.js";
import {parseFragment} from "./support/dom.js";

/**
 * These behaviours were unreachable from a test until row editing came out of
 * bootstrap.js -- nothing was exported. The interesting cases are the two edge
 * conditions a user hits constantly: the repeat limit, and removing the only
 * remaining row.
 */
const phoneList = (rowCount, limit) =>
    parseFragment(`
        <section>
            <div data-repeat-kind="numbers" data-repeat-limit="${limit}">
                ${Array.from({length: rowCount}, (_, index) => `
                    <div data-repeat-row="numbers">
                        <select data-phone-country><option value="PT">PT</option><option value="GB">GB</option></select>
                        <input data-phone-local value="91234567${index}">
                    </div>`).join("")}
            </div>
        </section>`).firstElementChild;

const rowsIn = (section) => section.querySelectorAll('[data-repeat-row="numbers"]');

test("adding a row clones the last one and clears its value", () => {
    const section = phoneList(1, 3);

    appendPhoneListRow(section, "numbers");

    const rows = rowsIn(section);
    assert.equal(rows.length, 2);
    assert.equal(rows[1].querySelector("[data-phone-local]").value, "");
});

test("a new row resets the country rather than inheriting the previous one", () => {
    const section = phoneList(1, 3);
    section.querySelector("[data-phone-country]").value = "GB";

    appendPhoneListRow(section, "numbers");

    assert.equal(rowsIn(section)[1].querySelector("[data-phone-country]").value, "PT");
});

test("the repeat limit is honoured", () => {
    const section = phoneList(2, 2);

    appendPhoneListRow(section, "numbers");

    assert.equal(rowsIn(section).length, 2);
});

test("an unknown row type is ignored rather than throwing", () => {
    const section = phoneList(1, 3);

    assert.doesNotThrow(() => appendPhoneListRow(section, "not-a-row-kind"));
    assert.equal(rowsIn(section).length, 1);
});

test("removing one of several rows deletes it", () => {
    const section = phoneList(2, 3);

    removeConfigRow(rowsIn(section)[1]);

    assert.equal(rowsIn(section).length, 1);
});

test("removing the last remaining row clears it instead of deleting it", () => {
    // Deleting it would leave the section with no row to clone from, so the
    // user could never add one back.
    const section = phoneList(1, 3);

    removeConfigRow(rowsIn(section)[0]);

    const rows = rowsIn(section);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].querySelector("[data-phone-local]").value, "");
    assert.equal(rows[0].querySelector("[data-phone-country]").value, "PT");
});

test("removing a row that is not attached to anything is harmless", () => {
    assert.doesNotThrow(() => removeConfigRow(null));
    assert.doesNotThrow(() => removeConfigRow(parseFragment("<div></div>").firstElementChild));
});
