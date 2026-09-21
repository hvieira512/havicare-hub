import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

const alarms = CONFIG_INPUTS.pillDispenserAlarms;
const entry = (fields, overrides = {}) => ({ fields, ...overrides });

test("os nove alarmes são desenhados, ocupados ou não", () => {
    const html = alarms.render(entry(["plans"]), {
        plans: [{ hour: 8, minute: 30, enabled: true }],
    });

    // Nove linhas: o aparelho tem nove slots fixos e mostra-os todos, senão não há como
    // ligar o décimo — que não existe — nem desligar um que ficou de um plano anterior.
    assert.equal((html.match(/data-alarm-slot/g) || []).length, 9);
    assert.match(html, /value="08:30"/);
});

test("o plano parte vazio sem rebentar", () => {
    const html = alarms.render(entry(["plans"]), {});

    assert.equal((html.match(/data-alarm-slot/g) || []).length, 9);
    assert.deepEqual(alarms.defaults(entry(["plans"])), { plans: [] });
});

test("o som, o idioma e o fuso são escolhas e não números soltos", () => {
    // Estes quatro eram números sem significado no ecrã. Passaram a usar o campo de escolha
    // genérico, com os valores e os rótulos que a especificação define.
    for (const name of ["pillDispenserSound", "pillDispenserRegion"]) {
        assert.equal(CONFIG_INPUTS[name], undefined, `${name} devia ter desaparecido`);
    }
    assert.equal(typeof CONFIG_INPUTS.select.render, "function");
});

test("o não incomodar desenha a janela inteira", () => {
    const html = CONFIG_INPUTS.pillDispenserQuietHours.render(
        entry(["enabled", "startHour", "startMinute", "endHour", "endMinute"]),
        { enabled: true, startHour: 22, startMinute: 0, endHour: 7, endMinute: 30 },
    );

    // Dois seletores de horas, e não quatro caixas de número: a janela lê-se de uma vez.
    for (const f of ["start", "end"]) {
        assert.match(html, new RegExp(`data-config-field="${f}"`));
    }
    assert.match(html, /value="22:00"/);
    assert.match(html, /value="07:30"/);
});

test("os interruptores do dispensador usam o campo padrão e não um bespoke", () => {
    // A toma antecipada e o bloqueio de criança são duas definições `toggle`, para caírem
    // no agrupamento compacto que a dashboard já faz aos interruptores seguidos.
    assert.equal(CONFIG_INPUTS.pillDispenserDispenseMode, undefined);
    assert.equal(typeof CONFIG_INPUTS.toggle.render, "function");
});

test("o período do plano desenha as duas datas e o interruptor", () => {
    const html = CONFIG_INPUTS.pillDispenserPeriod.render(
        entry(["enabled", "startDate", "endDate"]),
        { enabled: true, startDate: "2026-09-18", endDate: "2026-12-31" },
    );

    assert.match(html, /type="date"[^>]*data-config-field="startDate"/);
    assert.match(html, /data-config-field="endDate"/);
    assert.match(html, /2026-12-31/);
    // Sem período é um estado legítimo, e não um formulário por preencher.
    assert.deepEqual(CONFIG_INPUTS.pillDispenserPeriod.defaults(), {
        enabled: false,
        startDate: "",
        endDate: "",
    });
});

test("o plano de medicação não repete o rótulo que o cartão já mostra", () => {
    const html = alarms.render(entry(["plans"]), { plans: [] });

    assert.doesNotMatch(html, /Plano de medicação/);
});

test("todos os campos do dispensador declaram as quatro faces", () => {
    for (const name of [
        "pillDispenserAlarms",
        "pillDispenserPeriod",
        "pillDispenserQuietHours",
    ]) {
        const input = CONFIG_INPUTS[name];
        assert.ok(input, `${name} não está registado`);
        assert.equal(typeof input.render, "function", `${name}.render`);
        assert.equal(typeof input.read, "function", `${name}.read`);
        assert.equal(typeof input.defaults, "function", `${name}.defaults`);
    }
});
