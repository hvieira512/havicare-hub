import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import {parseFragment} from "./support/dom.js";

/**
 * O motor das linhas repetíveis, nas duas estratégias que ele tem de servir: clonar a última
 * linha e limpá-la, e desenhar uma de raiz.
 *
 * Os casos que interessam são as fronteiras em que se bate constantemente: o limite de
 * repetições, e remover a única linha que resta -- que numa lista que se clona tem de ficar
 * lá, senão fica-se sem molde e nunca mais se acrescenta nenhuma.
 */
const phoneList = (rowCount, limit) =>
    parseFragment(`
        <section data-config-section>
            <div data-repeat-list="numbers" data-repeat-limit="${limit}">
                ${Array.from({length: rowCount}, (_, index) => `
                    <div data-repeat-row="numbers">
                        <select data-phone-country><option value="PT">PT</option><option value="GB">GB</option></select>
                        <input data-phone-local value="91234567${index}">
                        <button data-action="removeRepeatRow"></button>
                    </div>`).join("")}
            </div>
        </section>`).firstElementChild;

const medicationList = (rowCount) =>
    parseFragment(`
        <section data-config-section>
            <div data-repeat-list="wonlexMedicationPlan">
                ${Array.from({length: rowCount}, () => `
                    <div data-repeat-row="wonlexMedicationPlan">
                        <span data-medication-plan-number>?</span>
                        <button data-action="removeRepeatRow"></button>
                    </div>`).join("")}
            </div>
        </section>`).firstElementChild;

const rowsIn = (section, kind) =>
    section.querySelectorAll(`[data-repeat-row="${kind}"]`);

const removeButtonIn = (row) => row.querySelector('[data-action="removeRepeatRow"]');

test("clonar: a linha nova sai da última, sem o valor dela", () => {
    const section = phoneList(1, 3);

    appendRepeatRow(section, "numbers");

    const rows = rowsIn(section, "numbers");
    assert.equal(rows.length, 2);
    assert.equal(rows[1].querySelector("[data-phone-local]").value, "");
});

test("clonar: o indicativo volta ao inicial em vez de herdar o anterior", () => {
    const section = phoneList(1, 3);
    section.querySelector("[data-phone-country]").value = "GB";

    appendRepeatRow(section, "numbers");

    assert.equal(rowsIn(section, "numbers")[1].querySelector("[data-phone-country]").value, "PT");
});

test("desenhar: a linha nova é construída de raiz e numerada", () => {
    const section = medicationList(1);

    appendRepeatRow(section, "wonlexMedicationPlan");

    const rows = rowsIn(section, "wonlexMedicationPlan");
    assert.equal(rows.length, 2);
    assert.equal(rows[1].querySelector("[data-medication-plan-number]").textContent, "2");
});

test("o limite de repetições é respeitado", () => {
    const section = phoneList(2, 2);

    appendRepeatRow(section, "numbers");

    assert.equal(rowsIn(section, "numbers").length, 2);
});

test("sem limite declarado acrescenta-se sempre", () => {
    const section = medicationList(1);

    appendRepeatRow(section, "wonlexMedicationPlan");
    appendRepeatRow(section, "wonlexMedicationPlan");

    assert.equal(rowsIn(section, "wonlexMedicationPlan").length, 3);
});

test("um tipo desconhecido é ignorado em vez de rebentar", () => {
    const section = phoneList(1, 3);

    assert.doesNotThrow(() => appendRepeatRow(section, "not-a-row-kind"));
    assert.equal(rowsIn(section, "numbers").length, 1);
});

test("remover uma linha de várias apaga-a", () => {
    const section = phoneList(2, 3);

    removeRepeatRow(removeButtonIn(rowsIn(section, "numbers")[1]));

    assert.equal(rowsIn(section, "numbers").length, 1);
});

test("na lista que se clona, remover a última linha limpa-a em vez de a apagar", () => {
    // Apagá-la deixava a secção sem linha de onde clonar, e nunca mais se acrescentava uma.
    const section = phoneList(1, 3);

    removeRepeatRow(removeButtonIn(rowsIn(section, "numbers")[0]));

    const rows = rowsIn(section, "numbers");
    assert.equal(rows.length, 1);
    assert.equal(rows[0].querySelector("[data-phone-local]").value, "");
    assert.equal(rows[0].querySelector("[data-phone-country]").value, "PT");
});

test("na lista que se desenha, remover a última linha apaga-a", () => {
    const section = medicationList(1);

    removeRepeatRow(removeButtonIn(rowsIn(section, "wonlexMedicationPlan")[0]));

    assert.equal(rowsIn(section, "wonlexMedicationPlan").length, 0);
});

test("remover a partir de um botão solto é inofensivo", () => {
    assert.doesNotThrow(() => removeRepeatRow(null));
    assert.doesNotThrow(() =>
        removeRepeatRow(parseFragment("<button></button>").firstElementChild),
    );
});
