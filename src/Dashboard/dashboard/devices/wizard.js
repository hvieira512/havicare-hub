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

    /**
     * Uma pergunta `optional` nao trava o passo: nao lhe responder e, em si, uma
     * resposta. A empresa e assim -- um dispositivo pode nao ter nenhuma -- e por isso
     * continua a ser feita, com a omissao escolhida a partida, sem impedir o avanco.
     */
    function blocks(question) {
        return !question.optional && !isAnswered(question);
    }

    /** Um passo esta completo quando nenhuma das suas perguntas o trava. */
    function isStepComplete(number) {
        return !inStep(number).some(blocks);
    }

    /**
     * A primeira pergunta sem resposta DENTRO do passo actual, ou null quando o passo
     * esta completo.
     *
     * Sem resposta e nao "que trave": uma pergunta opcional continua a ser feita ate ser
     * respondida, e o que a omissao dispensa e o avanco, nao a pergunta.
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

    function applyAnswer(key, value) {
        answers = {...answers, [key]: value};
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
        answers: () => ({...answers}),

        canAdvance: () => isStepComplete(step) && step < steps.length,
        canGoBack: () => step > 1,
        isLastStep: () => step === steps.length,

        /** Tudo o que e preciso respondido: e o que habilita o botao de criar. */
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
         * Responde e, se essa era a ultima pergunta do passo, avanca.
         *
         * Sem isto, responder a ultima deixava um ecra que nao perguntava nada -- so
         * dizia que o passo estava completo -- e obrigava a um clique no "Seguinte" entre
         * a ultima resposta e o campo seguinte.
         *
         * "Ultima" e nao ter nenhuma aberta, e nao o passo estar completo: uma pergunta
         * opcional nao trava o passo mas continua a ser feita, e avancar por cima dela era
         * saltar uma pergunta sem a mostrar.
         *
         * Nao e o mesmo que avancar sempre que o passo esta completo. O "Anterior" leva de
         * volta a um passo completo por definicao, e la nao se avanca -- senao nao havia
         * como voltar atras.
         */
        answerAndAdvance(key, value) {
            applyAnswer(key, value);
            if (current() === null && isStepComplete(step) && step < steps.length) {
                step += 1;
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
