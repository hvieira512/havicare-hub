import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { syncFallSensitivityLevels } from "../../src/Dashboard/dashboard/devices/config/inputs/four-p-touch.js";

/**
 * O nível 1 é «Máxima» e o 8 é «Mínima»: ao baixar de oito para seis níveis, um 7 ou um 8 desce
 * até ao 6, e não para o primeiro botão à vista, que é a sensibilidade máxima.
 */
const ENTRY = {
    key: "fallSensitivity",
    capabilityKey: "fall_sensitivity",
    command: "SENSITIVITY",
    label: "Sensibilidade de queda",
    input: "fallSensitivityLevels",
    fields: ["sensitivity", "levels"],
    category: "alerts",
};

const sectionWith = (sensitivity, levels) => parseFragment(
    renderConfigSection("four-p-touch", { ...ENTRY }, { sensitivity, levels }),
).firstElementChild;

const chosenLevel = (section) =>
    section.querySelector("[data-config-field=\"sensitivity\"]").value;

test("lowering the scale clamps the level to the highest left, not the first", () => {
    const section = sectionWith(7, 8);

    syncFallSensitivityLevels(section, 6);

    assert.equal(chosenLevel(section), "6", "o 7 desce para o 6, não salta para o 1");
});

test("a level that fits the new scale stays where it is", () => {
    const section = sectionWith(3, 8);

    syncFallSensitivityLevels(section, 6);

    assert.equal(chosenLevel(section), "3");
});

/** O botão que fica escolhido tem de ser o que o valor diz, ou o ecrã e o payload divergem. */
test("the active button follows the level it dropped to", () => {
    const section = sectionWith(8, 8);

    syncFallSensitivityLevels(section, 6);

    const active = section.querySelector(".sens-level-btn.active");
    assert.equal(active.dataset.configValue, "6");
});
