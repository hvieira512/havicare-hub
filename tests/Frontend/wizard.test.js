import test from "node:test";
import assert from "node:assert/strict";

import {createWizard} from "../../src/Dashboard/dashboard/devices/wizard.js";

/**
 * O motor do assistente, sem DOM: e logica pura e testa-se como tal.
 */

const STEPS = ["Classificação", "Este aparelho"];

function wizard() {
    return createWizard({
        steps: STEPS,
        questions: [
            {
                key: "type",
                step: 1,
                clears: ["model", "identity"],
                isAnswered: (a) => Boolean(a.type),
                badges: (a) => [{label: "Tipo", value: a.type}],
            },
            {
                key: "model",
                step: 1,
                clears: ["identity"],
                isAnswered: (a) => Boolean(a.model),
                badges: (a) => [{label: "Modelo", value: a.model}],
            },
            {
                key: "owner",
                step: 1,
                clears: [],
                isAnswered: (a) => Boolean(a.owner),
                badges: (a) => [
                    {label: "Empresa", value: a.owner.company},
                    {label: "Licença", value: a.owner.license},
                ],
            },
            {
                key: "identity",
                step: 2,
                clears: [],
                isAnswered: (a) => Boolean(a.identity),
                badges: () => [],
            },
        ],
    });
}

test("a pergunta activa e a primeira sem resposta", () => {
    const w = wizard();
    assert.equal(w.current().key, "type");

    w.answer("type", "diaper_sensor");
    assert.equal(w.current().key, "model");

    w.answer("model", "MECS-PRO");
    assert.equal(w.current().key, "owner");
});

test("responder tudo deixa de ter pergunta activa", () => {
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("owner", {company: "hitcare", license: "1001"});
    w.advance();
    w.answer("identity", "eec5000202f9");

    assert.equal(w.current(), null);
    assert.equal(w.isComplete(), true);
});

test("nao avanca com o passo incompleto", () => {
    const w = wizard();
    assert.equal(w.canAdvance(), false, "sem nada respondido");

    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    assert.equal(w.canAdvance(), false, "falta a empresa");

    w.answer("owner", {company: "hitcare", license: "1001"});
    assert.equal(w.canAdvance(), true);
});

test("nao avanca para alem do ultimo passo", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", {company: "c", license: "l"});
    w.advance();
    w.answer("identity", "i");

    assert.equal(w.step(), 2);
    assert.equal(w.isLastStep(), true);
    assert.equal(w.canAdvance(), false);
});

test("o passo so muda ao avancar, e nao ao responder", () => {
    // O que a primeira versao do motor fazia mal: derivava o passo da pergunta activa, e
    // a barra saltava para o 2 no instante em que a ultima resposta do 1 entrava -- antes
    // de a pessoa premir Seguinte, que e uma acao deliberada.
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", {company: "c", license: "l"});

    assert.equal(w.step(), 1, "responder nao avanca");
    assert.equal(w.current(), null, "mas o passo esta completo");
    assert.equal(w.canAdvance(), true);

    w.advance();
    assert.equal(w.step(), 2);
    assert.equal(w.current().key, "identity");
});

test("a pergunta activa nunca e de outro passo", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", {company: "c", license: "l"});

    // A identidade esta sem resposta, mas e do passo 2 e o assistente esta no 1.
    assert.equal(w.current(), null);
});

test("voltar atras e depois avancar preserva as respostas", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", {company: "c", license: "l"});
    w.advance();
    w.answer("identity", "i");

    w.back();
    assert.equal(w.step(), 1);
    assert.equal(w.canGoBack(), false, "ja esta no primeiro");

    w.advance();
    assert.equal(w.answers().identity, "i");
});

test("mudar o tipo limpa o modelo e a identidade", () => {
    // Um modelo escolhido pode nao existir no tipo novo, e uma identidade escrita pode
    // ter o formato errado. Declarado no `clears` e nao espalhado por quem trata o clique.
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("identity", "eec5000202f9");

    w.answer("type", "watch");

    assert.equal(w.answers().model, undefined);
    assert.equal(w.answers().identity, undefined);
    assert.equal(w.current().key, "model");
});

test("mudar o modelo limpa a identidade mas nao o tipo", () => {
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("identity", "eec5000202f9");

    w.answer("model", "OUTRO");

    assert.equal(w.answers().type, "diaper_sensor");
    assert.equal(w.answers().identity, undefined);
});

test("reabrir uma resposta de um passo anterior recua o passo", () => {
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("owner", {company: "hitcare", license: "1001"});
    w.advance();
    assert.equal(w.step(), 2);

    w.reopen("model");

    assert.equal(w.step(), 1, "a pergunta reaberta e do passo 1");
    assert.equal(w.current().key, "model");
});

test("reabrir uma resposta apaga-a e o que dela dependia", () => {
    // E o que o \"alterar\" ao lado de uma badge faz: volta aquela pergunta sem obrigar a
    // refazer as anteriores.
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("owner", {company: "hitcare", license: "1001"});

    w.reopen("model");

    assert.equal(w.current().key, "model");
    assert.equal(w.answers().type, "diaper_sensor", "a anterior fica");
    assert.deepEqual(w.answers().owner, {company: "hitcare", license: "1001"});
});

test("as badges saem na ordem das perguntas, e uma pergunta pode dar duas", () => {
    const w = wizard();
    w.answer("type", "Medidor de fraldas");
    w.answer("model", "MECS Pro");
    w.answer("owner", {company: "hitcare", license: "1001"});

    assert.deepEqual(w.badges(), [
        {key: "type", label: "Tipo", value: "Medidor de fraldas"},
        {key: "model", label: "Modelo", value: "MECS Pro"},
        {key: "owner", label: "Empresa", value: "hitcare"},
        {key: "owner", label: "Licença", value: "1001"},
    ]);
});

test("uma pergunta sem resposta nao produz badges", () => {
    const w = wizard();
    w.answer("type", "x");

    assert.equal(w.badges().length, 1);
});

test("reset volta ao inicio", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");

    w.reset();

    assert.equal(w.current().key, "type");
    assert.deepEqual(w.badges(), []);
});

test("as respostas devolvidas sao uma copia", () => {
    // Quem as le nao pode alterar o estado interno por acidente.
    const w = wizard();
    w.answer("type", "x");

    const snapshot = w.answers();
    snapshot.type = "mexido";

    assert.equal(w.answers().type, "x");
});

test("o passo 1 completa-se com as suas tres perguntas e nao com a do passo 2", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    assert.equal(w.isStepComplete(1), false);

    w.answer("owner", {company: "c", license: "l"});
    assert.equal(w.isStepComplete(1), true);
    assert.equal(w.isStepComplete(2), false);
});

test("uma pergunta opcional nao trava o passo, mas continua a ser feita", () => {
    // A empresa e assim: um dispositivo pode nao ter nenhuma, e por isso nao lhe
    // responder tem de deixar avancar -- sem que a pergunta desapareca do ecra, que e o
    // que aconteceria se a omissao contasse como resposta.
    const w = createWizard({
        steps: STEPS,
        questions: [
            {
                key: "type",
                step: 1,
                clears: [],
                isAnswered: (a) => Boolean(a.type),
                badges: () => [],
            },
            {
                key: "owner",
                step: 1,
                clears: [],
                optional: true,
                isAnswered: (a) => Boolean(a.owner),
                badges: () => [],
            },
            {
                key: "identity",
                step: 2,
                clears: [],
                isAnswered: (a) => Boolean(a.identity),
                badges: () => [],
            },
        ],
    });

    assert.equal(w.canAdvance(), false, "a obrigatoria ainda trava");

    w.answer("type", "x");
    assert.equal(w.current().key, "owner", "a opcional continua a ser feita");
    assert.equal(w.canAdvance(), true, "mas nao trava o avanco");

    w.advance();
    w.answer("identity", "i");
    assert.equal(w.isComplete(), true, "criar nao espera pela opcional");
});
