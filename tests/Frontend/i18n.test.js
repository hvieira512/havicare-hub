import test from "node:test";
import assert from "node:assert/strict";

import {
    UNTRANSLATED,
    capabilityLabel,
    commandLabel,
    configText,
    countLabel,
    languageLabel,
    optionLabel,
    sectionLabel,
    weekdayLabel,
} from "../../src/Dashboard/dashboard/i18n.js";

/** Uma chave sem tradução diz isso em português, e nunca a chave inglesa. */
test("a missing key shows a neutral Portuguese fallback", () => {
    assert.equal(capabilityLabel("not_a_capability"), UNTRANSLATED);
    assert.equal(sectionLabel("nowhere"), UNTRANSLATED);
    assert.equal(optionLabel("alarm_volume", "", "volume", 9), UNTRANSLATED);
});

/** O mesmo reinício diz coisas diferentes num relógio e num dispensador. */
test("a protocol's own text sits on top of the general one", () => {
    assert.match(configText("restart_device", "wonlex-json").confirm, /arranca/);
    assert.equal(configText("restart_device", "zayata-m228").confirm, "Reiniciar o dispensador?");
    assert.equal(configText("restart_device", "zayata-m228").label, "Reiniciar");
    assert.equal(configText("restart_device", "wonlex-json").label, "Reiniciar dispositivo");
});

test("the factory reset says it resets, not that it restarts", () => {
    const { confirm } = configText("reset_device", "wonlex-json");

    assert.match(confirm, /f[áa]brica/iu);
    assert.doesNotMatch(confirm, /arranca|reinici/iu);
});

test("the ECG blood pressure monitor asks for the memory screen before reading", () => {
    assert.match(configText("read_blood_pressure_history", "joytech-bp-ecg").help, /MEM/);
    assert.doesNotMatch(configText("read_blood_pressure_history", "joytech-bp").help, /MEM/);
});

/** Dois pedidos com o mesmo `feature` distinguem-se pelo `id`; os outros têm o nome da capacidade. */
test("a command is named by its id when it needs one, else by its capability", () => {
    assert.equal(commandLabel({ id: "pillReadConfiguration2", feature: "sync_configuration" }), "Configuração guardada (resto)");
    assert.equal(commandLabel({ id: "dnHeartRate", feature: "heart_rate" }), "Frequência cardíaca");
});

test("days, languages and counts come from Intl", () => {
    assert.deepEqual([1, 6, 7].map(weekdayLabel), ["Seg", "Sáb", "Dom"]);
    assert.equal(languageLabel("pt"), "Português");
    assert.equal(countLabel(1, "alarme", "alarmes"), "1 alarme");
    assert.equal(countLabel(3, "alarme", "alarmes"), "3 alarmes");
});
