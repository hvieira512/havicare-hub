import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o nome de uma capacidade vem do catálogo, e
// esse caminho passa pelo `api/http.js`, que toca em `window` ao carregar.
import "./support/browser-env.js";
import { requestCardShell as buildCard } from "../../src/Dashboard/dashboard/components/cards/request.js";

// O construtor devolve um fragmento de marcação; as assertivas de texto querem texto.
const requestCardShell = (...args) => String(buildCard(...args));
import { fieldLabel, fieldValue } from "../../src/Dashboard/dashboard/format.js";
import { cardIcon } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { allDetailItems } from "../../src/Dashboard/dashboard/devices/detail-filters.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

state.capabilityCatalogByType.pill_dispenser = [
    { key: "battery", label: "Bateria" },
    { key: "temperature", label: "Temperatura" },
    { key: "humidity", label: "Humidade" },
    { key: "medication_level", label: "Nível de medicação" },
    { key: "cells_remaining", label: "Células restantes" },
    { key: "device_status", label: "Estado do dispositivo" },
];
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
    assert.match(card("temperature", { environmentCelsius: 22 }), /-/);
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

test("nenhum campo do dispensador aparece em inglês", () => {
    assert.equal(fieldLabel("remaining"), "Restantes");
    assert.equal(fieldLabel("total"), "Total");
    assert.equal(fieldLabel("current"), "Atual");
    assert.equal(fieldLabel("level"), "Nível");
    assert.equal(fieldLabel("wifiSignalDbm"), "Sinal WiFi");
    assert.equal(fieldLabel("gsmSignalDbm"), "Sinal GSM");
    assert.equal(fieldLabel("alarmSlot"), "Alarme");
    assert.equal(fieldLabel("cellNumber"), "Compartimento");
    assert.equal(fieldLabel("scheduledAt"), "Hora prevista");
    assert.equal(fieldLabel("takenAt"), "Hora da toma");
});

test("os estados da toma e das avarias são traduzidos", () => {
    assert.equal(fieldValue("result", "missed"), "Falhada");
    assert.equal(fieldValue("result", "on_time"), "A horas");
    assert.equal(fieldValue("result", "abnormal"), "Anormal");
    assert.equal(fieldValue("method", "early"), "Antecipada");
    assert.equal(fieldValue("level", "empty"), "Sem medicação");
    assert.equal(fieldValue("fault", "tray_reset"), "Reposição do prato");
});

test("o sinal do dispensador sai em dBm", () => {
    assert.equal(fieldValue("wifiSignalDbm", -58), "-58 dBm");
    assert.equal(fieldValue("gsmSignalDbm", -81), "-81 dBm");
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

test("a toma e a avaria têm ícone próprio", () => {
    assert.equal(cardIcon("medication_intake"), "fa-pills");
    assert.equal(cardIcon("device_fault"), "fa-triangle-exclamation");
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

test("cada avaria tem tradução", () => {
    const faults = {
        rotation: "Rotação do prato",
        tray_reset: "Reposição do prato",
        pusher: "Empurrador",
        cell_door: "Porta do compartimento",
        keys: "Teclas",
    };

    for (const [fault, label] of Object.entries(faults)) {
        assert.equal(fieldValue("fault", fault), label);
    }
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
