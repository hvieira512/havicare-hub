import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o nome de uma capacidade vem do catalogo, e
// esse caminho passa pelo api/http.js, que toca em window ao carregar.
import "./support/browser-env.js";
import { requestCardShell } from "../../src/Dashboard/dashboard/components/cards/request.js";
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

test("a temperatura do dispensador é a do ambiente, e não a corporal", () => {
    // O aparelho mede a divisão onde está. Sem isto o cartão ficava a traço, porque só
    // procurava a temperatura corporal que um relógio entrega.
    assert.match(card("temperature", { environmentCelsius: 22 }), /22 °C/);
    assert.match(card("temperature", { environmentCelsius: -5 }), /-5 °C/);
});

test("a humidade sai com a unidade", () => {
    assert.match(card("humidity", { humidityPercent: 47 }), /47\s*%/);
});

test("o nível de medicação sai em português e não como enumeração crua", () => {
    const html = card("medication_level", { level: "low" });

    assert.match(html, /A acabar/);
    assert.doesNotMatch(html, /Low/);
});

/**
 * O «16 de 28» dizia uma coisa que não era verdade.
 *
 * O 28 é a capacidade do prato e o 16 é o que falta dispensar a partir de onde o carrossel
 * está — a conta que o aparelho faz é `carregados − posição`. Postos lado a lado liam-se como
 * «16 dos 28 compartimentos ainda têm medicação», que não é o que nenhum dos dois quer dizer.
 *
 * E a posição ficava por mostrar, que é precisamente o que uma pessoa precisa de saber para
 * carregar o prato: em que compartimento é que isto vai pegar a seguir.
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
    assert.equal(fieldValue("state", "in_progress"), "Em curso");
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

// A lista de atividade tem uma lista branca de tipos de evento, e o que não está nela é
// descartado em silêncio. Uma toma falhada que não aparece no histórico é o pior caso que
// esta integração pode ter.
test("a toma e a avaria chegam à lista de atividade", () => {
    state.selectedDetail.recent = {
        telemetry: [],
        events: [
            { type: "medication_intake", occurredAt: "2026-09-18T20:07:31Z", data: { result: "missed" } },
            { type: "device_fault", occurredAt: "2026-09-18T20:08:00Z", data: { fault: "tray_reset" } },
            { type: "help_call", occurredAt: "2026-09-18T20:09:00Z", data: { state: "in_progress" } },
        ],
    };

    const types = allDetailItems().map((item) => item.payload.type);

    assert.deepEqual(types, ["medication_intake", "device_fault", "help_call"]);
});

/** As cinco avarias que o aparelho reporta têm todas nome em português. */
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
