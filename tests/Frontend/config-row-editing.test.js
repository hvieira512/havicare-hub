import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import {
    appendPhoneListRow,
    removeConfigRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import {parseFragment} from "./support/dom.js";

/**
 * As linhas repetíveis de uma secção de configuração. Os casos que interessam são as duas
 * fronteiras em que se bate constantemente: o limite de repetições, e remover a única linha
 * que resta.
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
    // Apagá-la deixava a secção sem linha de onde clonar, e nunca mais se acrescentava uma.
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
