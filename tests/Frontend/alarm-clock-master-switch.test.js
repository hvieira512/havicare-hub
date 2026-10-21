import test from "node:test";
import assert from "node:assert/strict";

import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/** A Vivistar declara `['masterEnabled', 'items']`; a Wonlex e o 4P Touch só os itens. */
const WITH_MASTER = { input: "alarm_clock", key: "alarm_clock", fields: ["masterEnabled", "items"] };
const WITHOUT_MASTER = { input: "alarm_clock", key: "alarm_clock", fields: ["items"] };

const masterOf = (section) => section.querySelector("[data-alarm-clock-field=\"masterEnabled\"]");

test("a protocol that declares the master switch draws it", () => {
    assert.ok(masterOf(configSection(renderConfigInputs, WITH_MASTER, {}, {})), "a Vivistar tem-no");
    assert.equal(masterOf(configSection(renderConfigInputs, WITHOUT_MASTER, {}, {})), null, "os outros não");
});

test("the master switch goes in the payload with the state on screen", () => {
    const on = configSection(renderConfigInputs, WITH_MASTER, { masterEnabled: true }, {});
    assert.equal(readConfigPayload(on).masterEnabled, true);

    const off = configSection(renderConfigInputs, WITH_MASTER, { masterEnabled: false }, {});
    assert.equal(masterOf(off).checked, false, "o ecrã mostra-o desligado");
    assert.equal(readConfigPayload(off).masterEnabled, false);
});

/** Sem o campo declarado o payload não o inventa: o aparelho não o conhece. */
test("a protocol that does not declare it does not send it", () => {
    const payload = readConfigPayload(configSection(renderConfigInputs, WITHOUT_MASTER, {}, {}));

    assert.ok(!("masterEnabled" in payload), `veio na mesma: ${JSON.stringify(payload)}`);
});
