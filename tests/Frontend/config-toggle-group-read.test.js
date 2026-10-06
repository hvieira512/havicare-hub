import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { changedConfigEntries } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * A toma antecipada e o bloqueio de criança do dispensador declaram ambos o campo `enabled` e
 * partilham um «Enviar»: cada linha tem de enviar o **seu** valor.
 */

const toggleGroup = (rows) => parseFragment(`
    <section data-config-group data-config-protocol="zayata-m228">
        ${rows.map(({ key, on, stored }) => `
            <div data-config-row data-config-key="${key}" data-config-input="toggle"
                 data-config-stored="${stored ? "1" : "0"}"
                 data-config-pristine='${JSON.stringify({ enabled: stored })}'>
                <input type="checkbox" role="switch" data-config-field="enabled" ${on ? "checked" : ""}>
            </div>`).join("")}
    </section>`).querySelector("[data-config-group]");

test("desligar um interruptor envia esse e não o vizinho", () => {
    const group = toggleGroup([
        { key: "early_dispense", on: true, stored: true },
        { key: "child_lock", on: false, stored: true },
    ]);

    assert.deepEqual(changedConfigEntries(group), {
        child_lock: { enabled: false },
    });
});

test("com os dois alterados, cada um leva o seu valor", () => {
    const group = toggleGroup([
        { key: "early_dispense", on: false, stored: true },
        { key: "child_lock", on: true, stored: false },
    ]);

    assert.deepEqual(changedConfigEntries(group), {
        early_dispense: { enabled: false },
        child_lock: { enabled: true },
    });
});

test("sem alterações não se envia nada", () => {
    const group = toggleGroup([
        { key: "early_dispense", on: true, stored: true },
        { key: "child_lock", on: true, stored: true },
    ]);

    assert.deepEqual(changedConfigEntries(group), {});
});
