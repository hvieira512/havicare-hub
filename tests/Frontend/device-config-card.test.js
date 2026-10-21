import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";

/**
 * A configuração reportada numa resposta ao `0x05`; as compostas, como a lista de alarmes do
 * plano, descem mais do que um nível.
 */
const REPORTED = {
    settings: {
        medication_reminders: {
            plans: [
                { slot: 1, hour: 9, minute: 35, enabled: true },
                { slot: 2, hour: 20, minute: 0, enabled: true },
                { slot: 3, hour: 0, minute: 0, enabled: false },
            ],
        },
        medication_period: { enabled: false, startDate: "2026-09-02", endDate: "2026-09-03" },
        do_not_disturb: { enabled: true, startHour: 18, startMinute: 0, endHour: 9, endMinute: 0 },
        alarm_volume: { volume: 2 },
        alarm_ringtone: { ringtone: 1 },
        device_language: { language: 1 },
        time_zone: { timeZone: 100 },
        child_lock: { enabled: true },
        early_dispense: { enabled: true },
        retrieval_warning: { minutes: 30 },
        retrieval_timeout: { minutes: 60 },
        loaded_cells: { cells: 14 },
    },
};

const body = (data) => parseFragment(uplinkCardContent("device_config", data).body || "");

test("no setting comes out as [object Object]", () => {
    const content = uplinkCardContent("device_config", REPORTED);

    assert.doesNotMatch(String(content.body || ""), /\[object/);
    assert.doesNotMatch(String(content.details || ""), /\[object/);
});

/** A gaveta da lista de actividade escapa tudo: marcação em texto aparece à letra. */
test("the details carry no markup", () => {
    const content = uplinkCardContent("device_config", REPORTED);

    assert.doesNotMatch(content.details || "", /&lt;|<br/);
});

test("each setting has its own line", () => {
    assert.equal(body(REPORTED).querySelectorAll("[data-setting]").length, 12);
});

/** Um alarme desligado é um lugar vazio, e listá-lo como «00:00» é pior do que calá-lo. */
test("the schedule says the set times", () => {
    const row = body(REPORTED).querySelector("[data-setting=\"medication_reminders\"]");

    assert.match(row.textContent, /09:35/);
    assert.match(row.textContent, /20:00/);
    assert.doesNotMatch(row.textContent, /00:00/);
});

test("do-not-disturb says the window", () => {
    const row = body(REPORTED).querySelector("[data-setting=\"do_not_disturb\"]");

    assert.match(row.textContent, /18:00/);
    assert.match(row.textContent, /09:00/);
});

test("a schedule with no alarms set says it has none", () => {
    const row = body({ settings: { medication_reminders: { plans: [] } } })
        .querySelector("[data-setting=\"medication_reminders\"]");

    assert.match(row.textContent, /[Ss]em alarmes/);
});

test("the value counts the settings", () => {
    assert.match(uplinkCardContent("device_config", REPORTED).value, /12/);
});

/** Contar até um não diz nada: com uma só, o valor é o nome dela. */
test("a single setting gives its name", () => {
    const content = uplinkCardContent("device_config", {
        settings: { child_lock: { enabled: true } },
    });

    assert.doesNotMatch(content.value, /^1 /);
});

test("without settings there is no body", () => {
    assert.equal(uplinkCardContent("device_config", { settings: {} }).body || "", "");
});

/** Uma chave que o aparelho inventou não chega ao ecrã: diz que não tem tradução. */
test("a key the device invented shows as untranslated, never as markup", () => {
    const content = uplinkCardContent("device_config", {
        settings: {
            "<img src=x onerror=alert(1)>": { enabled: true },
            child_lock: { enabled: true },
        },
    });

    assert.doesNotMatch(content.details, /img/i);
    assert.match(content.details, /Sem tradução/);
});
