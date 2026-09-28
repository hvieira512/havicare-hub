import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    renderConfigInputs,
    readConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

/** A Vivistar declara `['masterEnabled', 'items']`; a Wonlex e o 4P Touch só os itens. */
const COM_MESTRE = { input: "alarm_clock", key: "alarm_clock", fields: ["masterEnabled", "items"] };
const SEM_MESTRE = { input: "alarm_clock", key: "alarm_clock", fields: ["items"] };

const masterOf = (section) => section.querySelector("[data-alarm-clock-field=\"masterEnabled\"]");

test("quem declara o interruptor mestre desenha-o", () => {
    assert.ok(masterOf(configSection(renderConfigInputs, COM_MESTRE, {}, {})), "a Vivistar tem-no");
    assert.equal(masterOf(configSection(renderConfigInputs, SEM_MESTRE, {}, {})), null, "os outros não");
});

test("o interruptor mestre vai no payload com o estado que está no ecrã", () => {
    const ligado = configSection(renderConfigInputs, COM_MESTRE, { masterEnabled: true }, {});
    assert.equal(readConfigPayload(ligado).masterEnabled, true);

    const desligado = configSection(renderConfigInputs, COM_MESTRE, { masterEnabled: false }, {});
    assert.equal(masterOf(desligado).checked, false, "o ecrã mostra-o desligado");
    assert.equal(readConfigPayload(desligado).masterEnabled, false);
});

/** Sem o campo declarado o payload não o inventa: o aparelho não o conhece. */
test("quem não o declara não o manda", () => {
    const payload = readConfigPayload(configSection(renderConfigInputs, SEM_MESTRE, {}, {}));

    assert.ok(!("masterEnabled" in payload), `veio na mesma: ${JSON.stringify(payload)}`);
});
