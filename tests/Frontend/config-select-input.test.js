import test from "node:test";
import assert from "node:assert/strict";

import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { asText } from "./support/dom.js";

/** O campo de escolha desenha as opções da definição com o nome do dicionário: «Baixo», e não `2`. */
const control = asText(CONFIG_INPUTS.select.control);

const VOLUME = {
    capabilityKey: "alarm_volume",
    fields: ["volume"],
    options: { volume: [{ value: 0 }, { value: 1 }, { value: 2 }, { value: 3 }] },
};
const META = { protocol: "zayata-m228" };

test("draws each option with its label, not the raw number", () => {
    const html = control(VOLUME, { volume: 2 }, META);

    assert.match(html, /<select[^>]*data-config-field="volume"/);
    for (const label of ["Alto", "Médio", "Baixo", "Silêncio"]) {
        assert.match(html, new RegExp(`>${label}<`));
    }
});

test("the stored option comes selected", () => {
    const html = control(VOLUME, { volume: 2 }, META);

    assert.match(html, /value="2"\s+selected/);
    assert.doesNotMatch(html, /value="0"\s+selected/);
});

test("an option the dictionary does not know says so, never in English", () => {
    const html = control({ ...VOLUME, options: { volume: [{ value: 9 }] } }, {}, META);

    assert.match(html, />Sem tradução</);
});

test("a setting without options does not throw", () => {
    assert.match(control({ fields: ["volume"] }, {}, META), /<select/);
});

/** O desvio de cada zona escreve-o o `Intl` com a hora de agora, e por isso não envelhece. */
test("a time zone is named by its id and its offset now", () => {
    const html = String(CONFIG_INPUTS.timeZone.control(
        { capabilityKey: "time_zone", fields: ["timeZone"], options: { timeZone: [{ value: "Europe/Lisbon" }, { value: "Asia/Tokyo" }] } },
        { timeZone: "Asia/Tokyo" },
        META,
    ));

    assert.match(html, />Asia\/Tokyo — GMT\+09:00</);
    assert.match(html, /value="Asia\/Tokyo"\s+selected/);
});
