import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o nome de uma capacidade vem do catalogo, e
// esse caminho passa pelo api/http.js, que toca em window ao carregar.
import "./support/browser-env.js";
import { renderRequestCardShell } from "../../src/Dashboard/dashboard/request-card.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

state.capabilityCatalogByType.pill_dispenser = [
    { key: "medication_alarm_status", label: "Estado dos alarmes" },
];
state.selectedDetail = { model: { deviceType: "pill_dispenser" } };

const card = (data) => renderRequestCardShell(
    { feature: "medication_alarm_status", requestable: false },
    false,
    [{ type: "medication_alarm_status", occurredAt: "2026-09-22T09:38:34Z", data }],
);

/**
 * O estado dos nove alarmes traz uma lista, e o renderizador genérico não sabe desenhar
 * listas: escrevia `Alarms: [object Object],[object Object]` no cartão, em produção.
 */
test("a lista de alarmes nunca aparece como [object Object]", () => {
    const rendered = card({
        takenCount: 1,
        missedCount: 1,
        alarms: [
            { alarm: 1, state: "taken" },
            { alarm: 3, state: "missed" },
        ],
    });

    assert.doesNotMatch(rendered, /\[object Object\]/);
});

/** Uma toma falhada é o que faz alguém olhar para o cartão, e por isso é o valor principal. */
test("uma toma falhada é o valor principal", () => {
    const rendered = card({
        takenCount: 2,
        missedCount: 1,
        alarms: [
            { alarm: 1, state: "taken" },
            { alarm: 2, state: "taken" },
            { alarm: 3, state: "missed" },
        ],
    });

    assert.match(rendered, /1 falhada/);
});

test("sem falhas, o valor principal são as tomas", () => {
    const rendered = card({
        takenCount: 2,
        missedCount: 0,
        alarms: [
            { alarm: 1, state: "taken" },
            { alarm: 2, state: "taken" },
        ],
    });

    assert.match(rendered, /2 tomadas/);
    assert.doesNotMatch(rendered, /falhada/);
});

/** Nove linhas de «Sem toma marcada» não são detalhe nenhum: só os alarmes vivos entram. */
test("os alarmes parados não enchem o cartão", () => {
    const rendered = card({
        takenCount: 0,
        missedCount: 0,
        alarms: [
            { alarm: 1, state: "idle" },
            { alarm: 2, state: "waiting" },
            { alarm: 3, state: "idle" },
        ],
    });

    assert.match(rendered, /Alarme 2/);
    assert.doesNotMatch(rendered, /Alarme 1/);
    assert.doesNotMatch(rendered, /Alarme 3/);
});

/** Nada em curso continua a ser uma leitura válida, e o cartão tem de a dizer em português. */
test("com tudo parado o cartão diz que não há tomas, e em português", () => {
    const rendered = card({
        takenCount: 0,
        missedCount: 0,
        alarms: [{ alarm: 1, state: "idle" }],
    });

    assert.doesNotMatch(rendered, /TakenCount|MissedCount|Alarms/);
    assert.match(rendered, /Sem tomas/);
});
