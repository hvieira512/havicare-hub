/**
 * O motor do assistente: perguntas em sequencia, respostas que colapsam em badges.
 *
 * Nao e uma biblioteca nem uma maquina de estados. Sabe tres coisas -- qual e a pergunta
 * activa, que respostas ja ha, e se pode avancar -- e o resto e desenhado por quem o usa.
 *
 * As perguntas sao uma lista declarativa. Cada uma diz a que passo pertence (para a barra
 * de progresso), como se le a resposta do estado, e que badges essa resposta produz. A
 * pergunta activa e a primeira sem resposta, o que faz a revelacao progressiva cair por
 * si: responder revela a seguinte, e limpar uma resposta faz voltar atras sem codigo
 * proprio para isso.
 *
 * `clears` e a unica coisa que nao e derivavel: responder ao tipo invalida o modelo, e
 * responder a empresa invalida a licenca, porque a resposta anterior pode nao existir no
 * conjunto novo. Declara-se, em vez de se espalhar por quem trata cada clique.
 */

export function createWizard({questions, steps}) {
    let answers = {};
    let step = 1;

    function isAnswered(question) {
        return question.isAnswered(answers);
    }

    function inStep(number) {
        return questions.filter((question) => question.step === number);
    }

    /** Um passo esta completo quando todas as suas perguntas tem resposta. */
    function isStepComplete(number) {
        return inStep(number).every(isAnswered);
    }

    /**
     * A primeira pergunta sem resposta DENTRO do passo actual, ou null quando o passo
     * esta completo.
     *
     * Limitada ao passo de proposito. Derivar o passo da pergunta activa, como a
     * primeira versao fazia, fazia a barra de progresso saltar para o passo seguinte no
     * instante em que a ultima resposta do actual entrava -- antes de a pessoa premir
     * "Seguinte", que e uma acao deliberada e nao uma consequencia.
     */
    function current() {
        return inStep(step).find((question) => !isAnswered(question)) ?? null;
    }

    /** As respondidas de todos os passos, na ordem das perguntas: a ordem da trilha. */
    function answered() {
        return questions.filter(isAnswered);
    }

    return {
        current,
        answered,
        isStepComplete,
        step: () => step,
        steps: () => steps,
        answers: () => ({...answers}),

        canAdvance: () => isStepComplete(step) && step < steps.length,
        canGoBack: () => step > 1,
        isLastStep: () => step === steps.length,

        /** Tudo respondido: e o que habilita o botao de criar. */
        isComplete: () => questions.every(isAnswered),

        advance() {
            if (isStepComplete(step) && step < steps.length) step += 1;
            return step;
        },

        back() {
            if (step > 1) step -= 1;
            return step;
        },

        answer(key, value) {
            answers = {...answers, [key]: value};
            const question = questions.find((q) => q.key === key);
            for (const cleared of question?.clears ?? []) {
                delete answers[cleared];
            }
            return answers;
        },

        /**
         * Voltar a uma pergunta ja respondida: apaga-a e o que dela dependia, e recua
         * o passo se a pergunta pertencer a um anterior.
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

        /** As badges de todas as respostas, achatadas na ordem das perguntas. */
        badges() {
            return answered().flatMap((question) =>
                question.badges(answers).map((badge) => ({...badge, key: question.key})),
            );
        },
    };
}
