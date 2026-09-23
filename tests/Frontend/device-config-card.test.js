import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * A configuração que o aparelho reporta, com as doze definições de uma resposta ao `0x05`.
 *
 * O caso que falta prender é o das compostas: o plano traz uma lista de alarmes, e um
 * formatador que só desce um nível escrevia `[object Object]` no ecrã.
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

test("nenhuma definição sai como [object Object]", () => {
    const content = uplinkCardContent("device_config", REPORTED);

    assert.doesNotMatch(content.body || "", /\[object/);
    assert.doesNotMatch(content.details || "", /\[object/);
});

/** A gaveta da lista de actividade escapa tudo: marcação em texto aparece à letra. */
test("os detalhes não levam marcação", () => {
    const content = uplinkCardContent("device_config", REPORTED);

    assert.doesNotMatch(content.details || "", /&lt;|<br/);
});

test("cada definição tem a sua linha", () => {
    assert.equal(body(REPORTED).querySelectorAll("[data-setting]").length, 12);
});

/** Um alarme desligado é um lugar vazio, e listá-lo como «00:00» é pior do que calá-lo. */
test("o plano diz as horas marcadas", () => {
    const row = body(REPORTED).querySelector("[data-setting=\"medication_reminders\"]");

    assert.match(row.textContent, /09:35/);
    assert.match(row.textContent, /20:00/);
    assert.doesNotMatch(row.textContent, /00:00/);
});

test("o não incomodar diz a janela", () => {
    const row = body(REPORTED).querySelector("[data-setting=\"do_not_disturb\"]");

    assert.match(row.textContent, /18:00/);
    assert.match(row.textContent, /09:00/);
});

test("um plano sem alarmes marcados diz que não tem", () => {
    const row = body({ settings: { medication_reminders: { plans: [] } } })
        .querySelector("[data-setting=\"medication_reminders\"]");

    assert.match(row.textContent, /[Ss]em alarmes/);
});

test("o valor conta as definições", () => {
    assert.match(uplinkCardContent("device_config", REPORTED).value, /12/);
});

/** Contar até um não diz nada: com uma só, o valor é o nome dela. */
test("uma definição sozinha dá o nome dela", () => {
    const content = uplinkCardContent("device_config", {
        settings: { child_lock: { enabled: true } },
    });

    assert.doesNotMatch(content.value, /^1 /);
});

test("sem definições não há corpo", () => {
    assert.equal(uplinkCardContent("device_config", { settings: {} }).body || "", "");
});
