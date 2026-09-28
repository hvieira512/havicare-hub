import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { appendRepeatRow } from "../../src/Dashboard/dashboard/devices/config/row-editing.js";
import { configSection } from "./support/dom.js";

const ENTRY = { input: "alarm_clock", key: "alarm_clock", fields: [] };

/** O motor lê o limite do `data-repeat-limit`; o texto do cartão promete o mesmo número. */
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
