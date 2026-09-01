/**
 * O motor do assistente: qual é a pergunta activa, que respostas há, e se pode avançar. A
 * pergunta activa é a primeira sem resposta, o que faz a revelação progressiva cair por si.
 *
 * O `clears` é a única coisa que não é derivável -- responder ao tipo invalida o modelo --, e
 * declara-se em vez de se espalhar por quem trata cada clique.
 */

export function createWizard({ questions, steps }) {
    let answers = {};
    let step = 1;

    function isAnswered(question) {
        return question.isAnswered(answers);
    }

    function inStep(number) {
        return questions.filter((question) => question.step === number);
    }

    /**
     * Uma pergunta `optional` não trava o passo: não lhe responder é, em si, uma resposta.
     * A licença é assim, e continua a ser feita, com a omissão escolhida à partida.
     */
    function blocks(question) {
        return !question.optional && !isAnswered(question);
    }

    /** Um passo está completo quando nenhuma das suas perguntas o trava. */
    function isStepComplete(number) {
        return !inStep(number).some(blocks);
    }

    /**
     * A primeira pergunta sem resposta dentro do passo actual. Limitada ao passo de
     * propósito: derivar o passo da pergunta fazia a barra de progresso saltar sozinha.
     */
    function current() {
        return inStep(step).find((question) => !isAnswered(question)) ?? null;
    }

    /** As respondidas de todos os passos, na ordem das perguntas: a ordem da trilha. */
    function answered() {
        return questions.filter(isAnswered);
    }

    function applyAnswer(key, value) {
        answers = { ...answers, [key]: value };
        const question = questions.find((q) => q.key === key);
        for (const cleared of question?.clears ?? []) {
            delete answers[cleared];
        }
        return answers;
    }

    return {
        current,
        answered,
        isStepComplete,
        step: () => step,
        steps: () => steps,
        answers: () => ({ ...answers }),

        canAdvance: () => isStepComplete(step) && step < steps.length,
        canGoBack: () => step > 1,
        isLastStep: () => step === steps.length,

        /** Tudo o que é preciso respondido: é o que habilita o botão de criar. */
        isComplete: () => !questions.some(blocks),

        advance() {
            if (isStepComplete(step) && step < steps.length) step += 1;
            return step;
        },

        back() {
            if (step > 1) step -= 1;
            return step;
        },

        answer: applyAnswer,

        /**
         * Responde e, se não sobrar nenhuma aberta, avança. "Nenhuma aberta" e não "passo
         * completo": uma pergunta opcional não trava o passo mas continua a ser feita, e o
         * "Anterior" leva a um passo completo onde não se pode avançar.
         */
        answerAndAdvance(key, value) {
            applyAnswer(key, value);
            if (current() === null && isStepComplete(step) && step < steps.length) {
                step += 1;
            }
            return answers;
        },

        /**
         * Voltar a uma pergunta já respondida: apaga-a e o que dela dependia, e recua o
         * passo se a pergunta pertencer a um anterior.
         */
        reopen(key) {
            const question = questions.find((q) => q.key === key);
            delete answers[key];
            for (const cleared of question?.clears ?? []) {
                delete answers[cleared];
            }
            if (question && question.step < step) step = question.step;
            return answers;
        },

        reset() {
            answers = {};
            step = 1;
            return answers;
        },

        /** As badges de todas as respostas, na ordem das perguntas. */
        badges() {
            return answered().flatMap((question) =>
                question.badges(answers).map((badge) => ({ ...badge, key: question.key })),
            );
        },
    };
}
