import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * A unidade que a definição declara ganha à que se adivinha pelo nome do campo.
 *
 * O cartão estreito tira a unidade do nome nativo do campo -- de «Intervalo (min)» sobrevive
 * o «min» ao lado da caixa. Para um campo que a tabela de nomes não conhece não sobrava nada,
 * e quem abrisse «Avisar de atraso ao fim de» via uma caixa com `30` e nada que dissesse se
 * eram minutos, horas ou segundos. A definição sempre soube a resposta: declara-a em
 * `options.label`, e ninguém a estava a ler.
 */
const section = (entry) => parseFragment(renderConfigSection("zayata-m228", entry, null));

test("a unidade declarada aparece ao lado do campo", () => {
    const rendered = section({
        key: "retrieval_warning",
        command: "retrievalWarning",
        label: "Avisar de atraso ao fim de",
        input: "number",
        fields: ["minutes"],
        options: { min: 0, max: 1440, label: "Minutos" },
    });

    assert.match(rendered.textContent, /Minutos/);
});

test("um campo sem unidade declarada continua a valer pela do nome nativo", () => {
    const rendered = section({
        key: "locationInterval",
        command: "locationInterval",
        label: "Intervalo de localização",
        input: "number",
        fields: ["intervalTime"],
    });

    assert.match(rendered.textContent, /\bs\b/);
});
