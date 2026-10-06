import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { renderPhoneControl } from "../../src/Dashboard/dashboard/phone.js";
import { parseFragment } from "./support/dom.js";

/** Um número cinzento num campo vazio lê-se como um número lá escrito. */

const control = (options) => parseFragment(renderPhoneControl(options));

test("o campo do número não tem placeholder nenhum", () => {
    const input = control({}).querySelector("[data-phone-local]");

    assert.equal(input.getAttribute("placeholder"), null);
});

test("nem quando quem o desenha insiste em passar um", () => {
    // A regra fica no controlo e não em quem o chama, para valer em qualquer sítio novo.
    const input = control({ placeholder: "+351912345678" }).querySelector("[data-phone-local]");

    assert.equal(input.getAttribute("placeholder"), null);
});

test("o resto do campo fica como estava", () => {
    const root = control({ value: "+351912345678", configField: "center_number", maxLength: 20 });
    const input = root.querySelector("[data-phone-local]");

    assert.equal(input.getAttribute("type"), "tel");
    assert.equal(input.getAttribute("maxlength"), "20");
    assert.ok(root.querySelector("[data-phone-country]"), "o seletor de país continua lá");
    assert.equal(root.querySelector("[data-phone-control]")?.dataset.configField, "center_number");
});
