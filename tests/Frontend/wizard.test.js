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

test("a pergunta activa é a primeira sem resposta", () => {
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
    w.answer("owner", { company: "hitcare", license: "1001" });
    w.advance();
    w.answer("identity", "eec5000202f9");

    assert.equal(w.current(), null);
    assert.equal(w.isComplete(), true);
});

test("não avança com o passo incompleto", () => {
    const w = wizard();
    assert.equal(w.canAdvance(), false, "sem nada respondido");

    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    assert.equal(w.canAdvance(), false, "falta a empresa");

    w.answer("owner", { company: "hitcare", license: "1001" });
    assert.equal(w.canAdvance(), true);
});

test("não avança para além do último passo", () => {
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

test("o passo só muda ao avançar, e não ao responder", () => {
    // Derivar o passo da pergunta activa fazia a barra saltar para o 2 no instante em que a
    // última resposta do 1 entrava, antes de alguém premir Seguinte.
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

test("a pergunta activa nunca e de outro passo", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    w.answer("owner", { company: "c", license: "l" });

    // A identidade está sem resposta, mas é do passo 2 e o assistente está no 1.
    assert.equal(w.current(), null);
});

test("voltar atrás e depois avançar preserva as respostas", () => {
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

test("mudar o tipo limpa o modelo e a identidade", () => {
    // Um modelo escolhido pode não existir no tipo novo, e uma identidade escrita pode ter o
    // formato errado. Declarado no `clears` e não espalhado por quem trata o clique.
    const w = wizard();
    w.answer("type", "diaper_sensor");
    w.answer("model", "MECS-PRO");
    w.answer("identity", "eec5000202f9");

    w.answer("type", "watch");

    assert.equal(w.answers().model, undefined);
    assert.equal(w.answers().identity, undefined);
    assert.equal(w.current().key, "model");
});

test("mudar o modelo limpa a identidade mas não o tipo", () => {
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
    w.answer("owner", { company: "hitcare", license: "1001" });
    w.advance();
    assert.equal(w.step(), 2);

    w.reopen("model");

    assert.equal(w.step(), 1, "a pergunta reaberta e do passo 1");
    assert.equal(w.current().key, "model");
});

test("reabrir uma resposta apaga-a e o que dela dependia", () => {
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

test("as badges saem na ordem das perguntas, e uma pergunta pode dar duas", () => {
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

test("uma pergunta sem resposta não produz badges", () => {
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

test("as respostas devolvidas são uma cópia", () => {
    // Quem as lê não pode alterar o estado interno por acidente.
    const w = wizard();
    w.answer("type", "x");

    const snapshot = w.answers();
    snapshot.type = "mexido";

    assert.equal(w.answers().type, "x");
});

test("o passo 1 completa-se com as suas três perguntas e não com a do passo 2", () => {
    const w = wizard();
    w.answer("type", "x");
    w.answer("model", "y");
    assert.equal(w.isStepComplete(1), false);

    w.answer("owner", { company: "c", license: "l" });
    assert.equal(w.isStepComplete(1), true);
    assert.equal(w.isStepComplete(2), false);
});

test("uma pergunta opcional não trava o passo, mas continua a ser feita", () => {
    // A licença é assim: um dispositivo pode não ter nenhuma, e por isso não lhe responder
    // tem de deixar avançar -- sem que a pergunta desapareça do ecrã, que é o que
    // aconteceria se a omissão contasse como resposta.
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

test("responder à última pergunta do passo avança-o", () => {
    // Um ecrã a dizer "este passo está completo" não pergunta nada: era um clique no
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

test("não avança por cima de uma pergunta opcional por responder", () => {
    // Uma opcional não trava o passo, mas continua a ser feita: avançar assim que o passo
    // ficasse completo era saltar-lhe por cima sem a mostrar.
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

    w.answerAndAdvance("type", "x");
    assert.equal(w.step(), 1, "a opcional ainda esta por fazer");
    assert.equal(w.current().key, "owner");

    w.answerAndAdvance("owner", {});
    assert.equal(w.step(), 2);
});

test("o Anterior leva a um passo completo e não ressalta para a frente", () => {
    // O avanço automático é consequência de responder e não de o passo estar completo: se
    // fosse do segundo, voltar atrás era impossível.
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
 * Um seed que responde a tudo deixa o assistente no último passo.
 *
 * O `answer` responde e fica quieto, que é o que se quer quando alguém clica numa opção. Mas
 * uma notificação de dispositivo não autorizado responde a tudo de uma vez, e o
 * `openCreateWizard` avança enquanto não houver nada por perguntar -- é esse contrato do
 * motor que isto prende.
 */
test("responder a tudo e avançar enquanto não há pergunta leva ao último passo", () => {
    const w = wizard();

    w.answer("type", "radar");
    w.answer("model", "RD-V1");
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
test("um seed sem licença fica no passo em que a pergunta está", () => {
    const w = wizard();

    w.answer("type", "radar");
    w.answer("model", "RD-V1");
    w.answer("identity", "594B3CB301AB");

    while (w.current() === null && w.canAdvance()) w.advance();

    assert.equal(w.step(), 1);
    assert.equal(w.current().key, "owner");
});
