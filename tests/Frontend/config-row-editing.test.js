import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import {
    appendRepeatRow,
    removeRepeatRow,
} from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection, parseFragment } from "./support/dom.js";

/**
 * O motor das linhas repetíveis, a clonar a última linha ou a desenhar uma de raiz; numa lista
 * que se clona, a única linha que resta não se remove, porque é o molde.
 */
const phoneList = (rowCount, limit) =>
    parseFragment(`
        <section data-config-section>
            <div data-repeat-list="numbers" data-repeat-limit="${limit}">
                ${Array.from({ length: rowCount }, (_, index) => `
                    <div data-repeat-row="numbers">
                        <select data-phone-country><option value="PT">PT</option><option value="GB">GB</option></select>
                        <input data-phone-local value="91234567${index}">
                        <button data-action="removeRepeatRow"></button>
                    </div>`).join("")}
            </div>
        </section>`).firstElementChild;

const reminderList = (rowCount) =>
    parseFragment(`
        <section data-config-section>
            <div data-repeat-list="takePillsReminder">
                ${Array.from({ length: rowCount }, () => `
                    <div data-repeat-row="takePillsReminder">
                        <span data-takepills-reminder-number>?</span>
                        <button data-action="removeRepeatRow"></button>
                    </div>`).join("")}
            </div>
        </section>`).firstElementChild;

const rowsIn = (section, kind) =>
    section.querySelectorAll(`[data-repeat-row="${kind}"]`);

const removeButtonIn = (row) => row.querySelector("[data-action=\"removeRepeatRow\"]");

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

test("desenhar: a linha nova é construída de raiz", () => {
    const section = reminderList(1);

    appendRepeatRow(section, "takePillsReminder");

    const rows = rowsIn(section, "takePillsReminder");
    assert.equal(rows.length, 2);
    // A linha do molde não tem campo nenhum: clonada, a nova também não teria.
    assert.ok(rows[1].querySelector("[data-takepills-field=\"reminderTime\"]"));
});

test("sem limite declarado acrescenta-se sempre", () => {
    const section = reminderList(1);

    appendRepeatRow(section, "takePillsReminder");
    appendRepeatRow(section, "takePillsReminder");

    assert.equal(rowsIn(section, "takePillsReminder").length, 3);
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
    // Apagá-la deixaria a secção sem linha de onde clonar.
    const section = phoneList(1, 3);

    removeRepeatRow(removeButtonIn(rowsIn(section, "numbers")[0]));

    const rows = rowsIn(section, "numbers");
    assert.equal(rows.length, 1);
    assert.equal(rows[0].querySelector("[data-phone-local]").value, "");
    assert.equal(rows[0].querySelector("[data-phone-country]").value, "PT");
});

test("na lista que se desenha, remover a última linha apaga-a", () => {
    const section = reminderList(1);

    removeRepeatRow(removeButtonIn(rowsIn(section, "takePillsReminder")[0]));

    assert.equal(rowsIn(section, "takePillsReminder").length, 0);
});

test("remover a partir de um botão solto é inofensivo", () => {
    assert.doesNotThrow(() => removeRepeatRow(null));
    assert.doesNotThrow(() =>
        removeRepeatRow(parseFragment("<button></button>").firstElementChild),
    );
});

/**
 * O sync é genérico -- corre para qualquer tipo repetível -- e por isso vale também para os
 * `keepLast`, como os contactos SOS e a whitelist.
 */
test("the add-item button tracks the limit for a rendered kind (takePillsReminder)", () => {
    const section = parseFragment(`
        <div data-config-section>
            <button data-action="addRepeatRow" data-repeat-kind="takePillsReminder">Adicionar item</button>
            <div data-repeat-list="takePillsReminder" data-repeat-limit="2"></div>
        </div>
    `).firstElementChild;
    const button = section.querySelector("[data-action=\"addRepeatRow\"]");

    appendRepeatRow(section, "takePillsReminder");
    assert.equal(button.disabled, false, "abaixo do limite o botão fica ligado");

    appendRepeatRow(section, "takePillsReminder");
    assert.equal(button.disabled, true, "no limite o botão desativa");

    const removeButton = section.querySelector("[data-action=\"removeRepeatRow\"]");
    assert.ok(removeButton, "a linha traz um botão de remover");
    removeRepeatRow(removeButton);
    assert.equal(button.disabled, false, "ao remover uma linha o botão volta a ligar");
});

test("the sync is generic: a keepLast kind (sos_contacts) also tracks its limit", () => {
    const section = parseFragment(`
        <div data-config-section>
            <button data-action="addRepeatRow" data-repeat-kind="sos_contacts">Adicionar contacto SOS</button>
            <div data-repeat-list="sos_contacts" data-repeat-limit="2">
                <div data-repeat-row="sos_contacts"><input value="911"><button data-action="removeRepeatRow">x</button></div>
            </div>
        </div>
    `).firstElementChild;
    const button = section.querySelector("[data-action=\"addRepeatRow\"]");
    const list = section.querySelector("[data-repeat-list=\"sos_contacts\"]");

    appendRepeatRow(section, "sos_contacts");
    assert.equal(list.querySelectorAll("[data-repeat-row=\"sos_contacts\"]").length, 2);
    assert.equal(button.disabled, true, "no limite o contacto SOS desativa, mesmo sem sync próprio");

    removeRepeatRow(list.querySelector("[data-repeat-row=\"sos_contacts\"] [data-action=\"removeRepeatRow\"]"));
    assert.equal(button.disabled, false, "ao remover volta a ligar");
});

const ALARM_CLOCK = { input: "alarm_clock", key: "alarm_clock", fields: [] };

/** O motor lê o limite do `data-repeat-limit`; o texto do cartão promete o mesmo número. */
test("o limite anunciado pelo cartão dos alarmes chega ao motor das linhas", () => {
    const section = configSection(renderConfigInputs, ALARM_CLOCK, {}, { limit: 3 });
    const list = section.querySelector("[data-repeat-list=\"alarm_clock\"]");
    const button = section.querySelector("[data-action=\"addRepeatRow\"]");

    assert.equal(list.dataset.repeatLimit, "3", "a lista declara o limite que o texto promete");

    appendRepeatRow(section, "alarm_clock");
    appendRepeatRow(section, "alarm_clock");
    assert.equal(
        section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]").length,
        3,
        "chega-se ao limite",
    );

    assert.equal(button.disabled, true, "no limite o botão desativa");
    appendRepeatRow(section, "alarm_clock");
    assert.equal(
        section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]").length,
        3,
        "e não se passa dele",
    );
});

const idsIn = (row) => [...row.querySelectorAll("[id]")].map((el) => el.id);
// Só os campos: o `name` do `<details>` é o grupo que fecha as irmãs, e esse é para partilhar.
const namesIn = (row) =>
    [...row.querySelectorAll("input[name], select[name], textarea[name]")].map((el) => el.name);

/**
 * A linha do alarme nasce por clonagem: com `id` repetidos, os rádios dos dois alarmes formam
 * um grupo só e os `for` do segundo apontam para as caixas do primeiro.
 */
test("um alarme acrescentado não repete os id nem os grupos do anterior", () => {
    const section = configSection(renderConfigInputs, ALARM_CLOCK, {}, { limit: 3 });
    appendRepeatRow(section, "alarm_clock");

    const [first, second] = section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]");
    assert.ok(second, "a segunda linha nasceu");

    const shared = idsIn(first).filter((id) => idsIn(second).includes(id));
    assert.deepEqual(shared, [], "nenhum `id` é partilhado pelas duas linhas");

    const sharedNames = namesIn(first).filter((name) => namesIn(second).includes(name));
    assert.deepEqual(sharedNames, [], "nenhum grupo de rádio é partilhado");
});

test("as etiquetas de um alarme apontam para dentro da própria linha", () => {
    const section = configSection(renderConfigInputs, ALARM_CLOCK, {}, { limit: 3 });
    appendRepeatRow(section, "alarm_clock");

    for (const row of section.querySelectorAll("[data-repeat-row=\"alarm_clock\"]")) {
        for (const label of row.querySelectorAll("label[for]")) {
            assert.ok(
                row.querySelector(`#${CSS.escape(label.htmlFor)}`),
                `a etiqueta "${label.textContent.trim()}" aponta para fora da linha`,
            );
        }
    }
});
