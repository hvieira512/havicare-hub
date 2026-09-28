import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { appendRepeatRow } from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

/**
 * O cartão anuncia «Até N alarmes» e o render corta a lista em N -- mas o motor que acrescenta
 * linhas lê o limite do `data-repeat-limit`, que o alarme não escrevia. Dava para passar do
 * limite, e o excedente desaparecia calado no render seguinte.
 */
const ENTRY = { input: "alarm_clock", key: "alarm_clock", fields: [] };

test("o limite anunciado pelo cartão dos alarmes chega ao motor das linhas", () => {
    const section = configSection(renderConfigInputs, ENTRY, {}, { limit: 3 });
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
