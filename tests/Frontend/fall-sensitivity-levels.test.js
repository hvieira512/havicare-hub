import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { syncFallSensitivityLevels } from "../../src/Dashboard/dashboard/devices/config/inputs/four-p-touch.js";

/**
 * O relógio 4P Touch tem escalas de queda de oito ou de seis níveis, e a escala escolhe-se no
 * mesmo cartão. O nível 1 é «Máxima» e o 8 é «Mínima».
 *
 * Ao baixar de oito para seis com um nível acima de seis escolhido, o valor tem de descer até
 * ao maior que ainda existe. Escolher o primeiro botão à vista punha-o no nível 1, ou seja na
 * sensibilidade **máxima** -- o oposto do que quem estava no 7 ou no 8 queria, e sem aviso
 * nenhum no ecrã.
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

test("baixar a escala prende o nível ao maior que resta, e não ao primeiro", () => {
    const section = sectionWith(7, 8);

    syncFallSensitivityLevels(section, 6);

    assert.equal(chosenLevel(section), "6", "o 7 desce para o 6, não salta para o 1");
});

test("o nível mais alto de todos desce igualmente até ao limite novo", () => {
    const section = sectionWith(8, 8);

    syncFallSensitivityLevels(section, 6);

    assert.equal(chosenLevel(section), "6");
});

test("um nível que cabe na escala nova fica onde está", () => {
    const section = sectionWith(3, 8);

    syncFallSensitivityLevels(section, 6);

    assert.equal(chosenLevel(section), "3");
});

/** O botão que fica escolhido tem de ser o que o valor diz, ou o ecrã e o payload divergem. */
test("o botão activo acompanha o nível a que se desceu", () => {
    const section = sectionWith(8, 8);

    syncFallSensitivityLevels(section, 6);

    const active = section.querySelector(".sens-level-btn.active");
    assert.equal(active.dataset.configValue, "6");
});
