/** O motor do assistente: a pergunta activa é a primeira sem resposta, e o `clears` o que cada uma invalida. */

export function createWizard({ questions, steps }) {
    let answers = {};
    let step = 1;

    function isAnswered(question) {
        return question.isAnswered(answers);
    }

    function inStep(number) {
        return questions.filter((question) => question.step === number);
    }

    /** Uma pergunta `optional` não trava o passo: não lhe responder é, em si, uma resposta. */
    function blocks(question) {
        return !question.optional && !isAnswered(question);
    }

    function isStepComplete(number) {
        return !inStep(number).some(blocks);
    }

    /** A primeira pergunta sem resposta, só no passo actual, para a barra não saltar sozinha. */
    function current() {
        return inStep(step).find((question) => !isAnswered(question)) ?? null;
    }

    /** As respondidas de todos os passos, na ordem da trilha. */
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

        /** Habilita o botão de criar. */
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

        /** Avança só se não sobrar nenhuma aberta: uma opcional não trava, mas pergunta-se. */
        answerAndAdvance(key, value) {
            applyAnswer(key, value);
            if (current() === null && isStepComplete(step) && step < steps.length) {
                step += 1;
            }
            return answers;
        },

        /** Apaga a resposta e o que dela dependia, e recua o passo se a pergunta for de um anterior. */
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

        badges() {
            return answered().flatMap((question) =>
                question.badges(answers).map((badge) => ({ ...badge, key: question.key })),
            );
        },
    };
}
