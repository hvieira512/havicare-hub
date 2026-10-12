import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { renderPhoneControl } from "../../src/Dashboard/dashboard/phone.js";
import { parseFragment } from "./support/dom.js";

/** Um número cinzento num campo vazio lê-se como um número lá escrito. */
const control = (options) => parseFragment(renderPhoneControl(options));

test("o campo do número não tem placeholder, nem quando quem o desenha passa um", () => {
    // A regra fica no controlo e não em quem o chama, para valer em qualquer sítio novo.
    const input = control({ placeholder: "+351912345678" }).querySelector("[data-phone-local]");

    assert.equal(input.getAttribute("placeholder"), null);
});
