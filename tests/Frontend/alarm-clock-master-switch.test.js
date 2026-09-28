import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/** A Vivistar declara `['masterEnabled', 'items']`; a Wonlex e o 4P Touch só os itens. */
const WITH_MASTER = { input: "alarm_clock", key: "alarm_clock", fields: ["masterEnabled", "items"] };
const WITHOUT_MASTER = { input: "alarm_clock", key: "alarm_clock", fields: ["items"] };

const masterOf = (section) => section.querySelector("[data-alarm-clock-field=\"masterEnabled\"]");

test("quem declara o interruptor mestre desenha-o", () => {
    assert.ok(masterOf(configSection(renderConfigInputs, WITH_MASTER, {}, {})), "a Vivistar tem-no");
    assert.equal(masterOf(configSection(renderConfigInputs, WITHOUT_MASTER, {}, {})), null, "os outros não");
});

test("o interruptor mestre vai no payload com o estado que está no ecrã", () => {
    const on = configSection(renderConfigInputs, WITH_MASTER, { masterEnabled: true }, {});
    assert.equal(readConfigPayload(on).masterEnabled, true);

    const off = configSection(renderConfigInputs, WITH_MASTER, { masterEnabled: false }, {});
    assert.equal(masterOf(off).checked, false, "o ecrã mostra-o desligado");
    assert.equal(readConfigPayload(off).masterEnabled, false);
});

/** Sem o campo declarado o payload não o inventa: o aparelho não o conhece. */
test("quem não o declara não o manda", () => {
    const payload = readConfigPayload(configSection(renderConfigInputs, WITHOUT_MASTER, {}, {}));

    assert.ok(!("masterEnabled" in payload), `veio na mesma: ${JSON.stringify(payload)}`);
});
