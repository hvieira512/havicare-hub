import test from "node:test";
import assert from "node:assert/strict";

import { createWizard } from "../../src/Dashboard/dashboard/devices/wizard.js";

/** O motor do assistente, sem DOM: é lógica pura e testa-se como tal. */

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
                badges: (a) => [{ label: "Tipo", value: a.type }],
            },
            {
                key: "model",
                step: 1,
                clears: ["identity"],
                isAnswered: (a) => Boolean(a.model),
                badges: (a) => [{ label: "Modelo", value: a.model }],
            },
            {
                key: "owner",
                step: 1,
                clears: [],
                isAnswered: (a) => Boolean(a.owner),
                badges: (a) => [
                    { label: "Empresa", value: a.owner.company },
                    { label: "Licença", value: a.owner.license },
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

/** Um dispositivo pode não ter licença: a empresa é opcional. */
function wizardWithOptionalOwner() {
    const question = (key, step, extra = {}) => ({
        key,
        step,
        clears: [],
        isAnswered: (a) => Boolean(a[key]),
        badges: () => [],
        ...extra,
    });

    return createWizard({
        steps: STEPS,
        questions: [question("type", 1), question("owner", 1, { optional: true }), question("identity", 2)],
    });
}

test("the active question is the first unanswered one", () => {
    const w = wizard();
    assert.equal(w.current().key, "type");

    w.answer("type", "diaper_sensor");
    assert.equal(w.current().key, "model");

    w.answer("model", "MECS-PRO");
    assert.equal(w.current().key, "owner");
});

test("it does not advance with an incomplete step", () => {
    const w = wizard();
    assert.equal(w.canAdvance(), false, "sem nada respondido");

    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    assert.equal(w.canAdvance(), false, "falta a empresa");

    w.answer("owner", { company: "hitcare", license: "1001" });
    assert.equal(w.canAdvance(), true);
});

test("it does not advance past the last step", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", { company: "c", license: "l" });
    w.advance();
    w.answer("identity", "i");

    assert.equal(w.step(), 2);
    assert.equal(w.isLastStep(), true);
    assert.equal(w.canAdvance(), false);
});

test("the step changes only on advance, not on answer", () => {
    // O passo não se deriva da pergunta activa: a barra saltaria para o 2 com a última
    // resposta do 1, antes de alguém premir Seguinte.
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", { company: "c", license: "l" });

    assert.equal(w.step(), 1, "responder nao avanca");
    assert.equal(w.current(), null, "mas o passo esta completo");
    assert.equal(w.canAdvance(), true);

    w.advance();
    assert.equal(w.step(), 2);
    assert.equal(w.current().key, "identity");
});

test("going back and then forward keeps the answers", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", { company: "c", license: "l" });
    w.advance();
    w.answer("identity", "i");

    w.back();
    assert.equal(w.step(), 1);
    assert.equal(w.canGoBack(), false, "ja esta no primeiro");

    w.advance();
    assert.equal(w.answers().identity, "i");
});

test("changing the type clears the model and the identity", () => {
    // Um modelo escolhido pode não existir no tipo novo, e uma identidade escrita pode ter o
    // formato errado.
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("identity", "eec5000202f9");

    w.answer("type", "watch");

    assert.equal(w.answers().model, undefined);
    assert.equal(w.answers().identity, undefined);
    assert.equal(w.current().key, "model");
});

test("changing the model clears the identity but not the type", () => {
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("identity", "eec5000202f9");

    w.answer("model", "OUTRO");

    assert.equal(w.answers().type, "diaper_sensor");
    assert.equal(w.answers().identity, undefined);
});

test("reopening an answer from an earlier step moves the step back", () => {
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("owner", { company: "hitcare", license: "1001" });
    w.advance();
    assert.equal(w.step(), 2);

    w.reopen("model");

    assert.equal(w.step(), 1, "a pergunta reaberta e do passo 1");
    assert.equal(w.current().key, "model");
});

test("reopening an answer erases it and what depended on it", () => {
    // É o que o clique numa badge faz: volta àquela pergunta sem obrigar a refazer as
    // anteriores.
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("owner", { company: "hitcare", license: "1001" });

    w.reopen("model");

    assert.equal(w.current().key, "model");
    assert.equal(w.answers().type, "diaper_sensor", "a anterior fica");
    assert.deepEqual(w.answers().owner, { company: "hitcare", license: "1001" });
});

test("badges come in question order, and one question can give two", () => {
    const w = wizard();
    w.answer("type", "Medidor de fraldas");
    w.answer("model", "MECS Pro");
    w.answer("owner", { company: "hitcare", license: "1001" });

    assert.deepEqual(w.badges(), [
        { key: "type", label: "Tipo", value: "Medidor de fraldas" },
        { key: "model", label: "Modelo", value: "MECS Pro" },
        { key: "owner", label: "Empresa", value: "hitcare" },
        { key: "owner", label: "Licença", value: "1001" },
    ]);
});

test("reset goes back to the start", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");

    w.reset();

    assert.equal(w.current().key, "type");
    assert.deepEqual(w.badges(), []);
});

test("the returned answers are a copy", () => {
    // Quem as lê não pode alterar o estado interno por acidente.
    const w = wizard();
    w.answer("type", "x");

    const snapshot = w.answers();
    snapshot.type = "mexido";

    assert.equal(w.answers().type, "x");
});

test("step 1 completes with its three questions, not with step 2's", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    assert.equal(w.isStepComplete(1), false);

    w.answer("owner", { company: "c", license: "l" });
    assert.equal(w.isStepComplete(1), true);
    assert.equal(w.isStepComplete(2), false);
});

test("an optional question does not block the step but is still asked", () => {
    // Um dispositivo pode não ter licença: não lhe responder deixa avançar, sem a pergunta
    // desaparecer do ecrã, como desapareceria se a omissão contasse como resposta.
    const w = wizardWithOptionalOwner();

    assert.equal(w.canAdvance(), false, "a obrigatoria ainda trava");

    w.answer("type", "x");
    assert.equal(w.current().key, "owner", "a opcional continua a ser feita");
    assert.equal(w.canAdvance(), true, "mas nao trava o avanco");

    w.advance();
    w.answer("identity", "i");
    assert.equal(w.isComplete(), true, "criar nao espera pela opcional");
});

test("answering the step's last question advances it", () => {
    // Um ecrã a dizer "este passo está completo" não pergunta nada: é um clique a mais no
    // "Seguinte" entre a última resposta e o campo seguinte.
    const w = wizard();
    w.answerAndAdvance("type", "x");
    assert.equal(w.step(), 1, "ainda faltam perguntas neste passo");

    w.answerAndAdvance("model", "y");
    assert.equal(w.step(), 1);

    w.answerAndAdvance("owner", { company: "c", license: "l" });
    assert.equal(w.step(), 2, "a ultima do passo 1 leva ao passo 2");
    assert.equal(w.current().key, "identity");
});

test("it does not skip past an unanswered optional question", () => {
    // Uma opcional não trava o passo, mas continua a ser feita: avançar assim que o passo
    // ficasse completo seria saltar-lhe por cima sem a mostrar.
    const w = wizardWithOptionalOwner();

    w.answerAndAdvance("type", "x");
    assert.equal(w.step(), 1, "a opcional ainda esta por fazer");
    assert.equal(w.current().key, "owner");

    w.answerAndAdvance("owner", {});
    assert.equal(w.step(), 2);
});

test("Anterior leads to a complete step and does not bounce forward", () => {
    // O avanço automático é consequência de responder e não de o passo estar completo: se
    // fosse do segundo, voltar atrás seria impossível.
    const w = wizard();
    w.answerAndAdvance("type", "x");
    w.answerAndAdvance("model", "y");
    w.answerAndAdvance("owner", { company: "c", license: "l" });
    assert.equal(w.step(), 2);

    w.back();
    assert.equal(w.step(), 1);
    assert.equal(w.current(), null, "completo, mas sem avancar sozinho");
});

/**
 * Uma notificação de dispositivo não autorizado responde a tudo de uma vez, e o
 * `openCreateWizard` avança enquanto não houver nada por perguntar.
 */
test("answering everything and advancing while there is no question reaches the last step", () => {
    const w = wizard();

    w.answer("type", "radar");
    w.answer("model", "HC2");
    w.answer("owner", { company: "hitcare", license: "2004" });
    w.answer("identity", "594B3CB301AB");

    assert.equal(w.step(), 1, "responder nao avanca por si");

    while (w.current() === null && w.canAdvance()) w.advance();

    assert.equal(w.step(), 2);
    assert.equal(w.isLastStep(), true);
    assert.equal(w.isComplete(), true);
    assert.equal(w.current(), null, "nao sobra nada para perguntar");
});

/** Um seed incompleto pára onde faltar, e não salta por cima da pergunta que falta. */
test("a seed without a licence stays on the step where the question is", () => {
    const w = wizard();

    w.answer("type", "radar");
    w.answer("model", "HC2");
    w.answer("identity", "594B3CB301AB");

    while (w.current() === null && w.canAdvance()) w.advance();

    assert.equal(w.step(), 1);
    assert.equal(w.current().key, "owner");
});
