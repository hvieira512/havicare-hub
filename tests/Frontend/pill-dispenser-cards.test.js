import test from "node:test";
import assert from "node:assert/strict";

import { requestCardShell as buildCard } from "../../src/Dashboard/dashboard/components/cards/request.js";

const requestCardShell = asText(buildCard);
import { cardContent } from "./support/cards.js";
import { allDetailItems } from "../../src/Dashboard/dashboard/devices/detail-filters.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { asText } from "./support/dom.js";

state.capabilityCatalogByType.pill_dispenser = [{ key: "cells_remaining", label: "Células restantes" }];
state.selectedDetail = { model: { deviceType: "pill_dispenser" } };

const card = (type, data) => requestCardShell(
    { feature: type, requestable: false },
    false,
    [{ type, occurredAt: "2026-09-18T20:05:04Z", data }],
);

test("a temperatura do dispensador tem chave própria e não a do corpo", () => {
    // O `0x810E` é o ar onde o aparelho está, e não a temperatura corporal dos relógios.
    assert.match(card("ambient_temperature", { environmentCelsius: 22 }), /22 °C/);
    assert.match(card("ambient_temperature", { environmentCelsius: -5 }), /-5 °C/);
    assert.doesNotMatch(card("temperature", { environmentCelsius: 22 }), /22 °C/);
});

test("a humidade sai com a unidade", () => {
    assert.match(card("ambient_humidity", { humidityPercent: 47 }), /47\s*%/);
});

test("o nível de medicação sai em português e não como enumeração crua", () => {
    const html = card("medication_level", { level: "low" });

    assert.match(html, /A acabar/);
    assert.doesNotMatch(html, /Low/);
});

/**
 * O 28 é a capacidade do prato e o 16 o que falta dispensar a partir da posição do carrossel
 * (`carregados − posição`); a posição diz em que compartimento o prato pega a seguir.
 */
test("as células dizem quantas faltam dispensar e em que compartimento vai o prato", () => {
    const html = card("cells_remaining", { remaining: 16, total: 28, current: 12 });

    assert.match(html, /16 por dispensar/);
    assert.match(html, /[Cc]ompartimento 12 de 28/);
    assert.doesNotMatch(html, /Remaining|Total:|Current/);
});

test("sem nenhuma por dispensar, o cartão di-lo por palavras", () => {
    const html = card("cells_remaining", { remaining: 0, total: 28, current: 21, level: "empty" });

    assert.match(html, /Nenhuma por dispensar/);
    assert.match(html, /[Cc]ompartimento 21 de 28/);
});

/**
 * O prato não tem números, tem grupos de doses e uma marca de início: com três doses por dia, a
 * posição 21 lê-se como o fim do sétimo dia, e o número cru fica na gaveta.
 */
test("com um plano de três doses por dia, a posição lê-se em dias", (t) => {
    const anterior = state.selectedDetail;
    t.after(() => {
        state.selectedDetail = anterior;
    });
    state.selectedDetail = {
        effectiveConfigurations: {
            medication_reminders: {
                plans: [
                    { times: [{ time: "08:00", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
                    { times: [{ time: "13:00", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
                    { times: [{ time: "20:00", enabled: true, slot: 3, recurrence: { kind: "daily" } }] },
                ],
            },
        },
    };

    const html = card("cells_remaining", { remaining: 6, total: 28, current: 21, level: "ok" });

    assert.match(html, /Dia 7, 3ª dose/);
    assert.match(html, /[Cc]ompartimento 21 de 28/);
});

test("sem plano, fica só o número que o aparelho conta", (t) => {
    const anterior = state.selectedDetail;
    t.after(() => {
        state.selectedDetail = anterior;
    });
    state.selectedDetail = { effectiveConfigurations: {} };

    const html = card("cells_remaining", { remaining: 6, total: 28, current: 21, level: "ok" });

    assert.match(html, /[Cc]ompartimento 21 de 28/);
    assert.doesNotMatch(html, /Dia \d/);
});

test("a toma mostra o resultado como valor, e a avaria mostra qual foi", () => {
    // O valor principal é o facto que interessa, e não o nome da capacidade outra vez.
    const intake = card("medication_intake", {
        alarmSlot: 3,
        cellNumber: 12,
        result: "missed",
        method: "late",
    });
    assert.match(intake, /Falhada/);

    const fault = card("device_fault", { fault: "tray_reset" });
    assert.match(fault, /Reposição do prato/);
});

// Uma toma falhada fora do histórico é o pior caso desta integração.
test("a toma e a avaria chegam à lista de atividade", () => {
    state.selectedDetail.recent = {
        telemetry: [],
        events: [
            { type: "medication_intake", severity: "alert", occurredAt: "2026-09-18T20:07:31Z", data: { result: "missed" } },
            { type: "device_fault", severity: "alert", occurredAt: "2026-09-18T20:08:00Z", data: { fault: "tray_reset" } },
            { type: "help_call", severity: "alarm", occurredAt: "2026-09-18T20:09:00Z", data: {} },
        ],
    };

    const types = allDetailItems().map((item) => item.payload.type);

    assert.deepEqual(types, ["medication_intake", "device_fault", "help_call"]);
});

/** Quantas doses faltam não diz quando é preciso recarregar; a data diz. */
test("com plano, a linha visível do cartão diz quando a medicação acaba", (t) => {
    const previous = state.selectedDetail;
    t.after(() => {
        state.selectedDetail = previous;
    });
    state.selectedDetail = {
        effectiveConfigurations: {
            medication_reminders: {
                plans: [
                    { times: [{ time: "08:00", enabled: true, slot: 1, recurrence: { kind: "daily" } }] },
                    { times: [{ time: "20:00", enabled: true, slot: 2, recurrence: { kind: "daily" } }] },
                ],
            },
        },
    };

    const html = card("cells_remaining", { remaining: 3, total: 28, current: 3, level: "low" });

    assert.match(html, /Acaba /);
    assert.match(html, /A acabar/);
    assert.match(html, /title="[^"]*Compartimento 3 de 28[^"]*"/);
    assert.match(html, /title="[^"]*Dia 2, 1ª dose[^"]*"/);
});

/** Um período já terminado suspende o plano, e os alarmes que faltavam nunca tocam. */
test("com o período do plano terminado, o cartão volta a falar em compartimentos", (t) => {
    const previous = state.selectedDetail;
    t.after(() => {
        state.selectedDetail = previous;
    });
    state.selectedDetail = {
        effectiveConfigurations: {
            medication_reminders: { plans: [{ times: [{ time: "08:00", enabled: true, slot: 1, recurrence: { kind: "daily" } }] }] },
            medication_period: { enabled: true, startDate: "2020-01-01", endDate: "2020-01-02" },
        },
    };

    const html = card("cells_remaining", { remaining: 3, total: 28, current: 3, level: "low" });

    assert.doesNotMatch(html, /Acaba /);
    assert.match(html, /Dia 3/);
});

/** O ambiente só chega quando dispara: o valor diz o que aconteceu, e não leva legenda fixa. */
test("o ambiente diz o que aconteceu", () => {
    const { value, details } = cardContent("storage_environment", { outOfRange: true });

    assert.match(value, /[Tt]emperatura/);
    assert.match(value, /[Hh]umidade/);
    assert.equal(details || "", "");
});
