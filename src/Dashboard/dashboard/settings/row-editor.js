/**
 * A edição em linha das listagens das definições.
 *
 * Cada listagem tinha a sua cópia da mesma mecânica: uma variável de módulo com quem está
 * aberto, o render a escolher entre a linha de ver e a linha de editar, o `closest` até ao
 * invólucro para lhe ler os `data-field`, e o foco no primeiro campo depois de repintar. Eram
 * três cópias em dois ficheiros -- empresa, licença e utilizador da API --, e cada uma com o
 * seu nome de atributo (`data-company-editor`, `data-license-editor`, `data-api-user-editor`).
 * A quarta listagem trazia a quarta cópia.
 *
 * O que varia entre elas é o que cada uma desenha e o que grava. O resto vive aqui.
 */

/**
 * A linha aberta para edição, numa listagem onde só pode estar uma.
 *
 * O `kind` é o que permite a uma listagem ter mais do que um tipo de linha editável -- as
 * empresas têm a empresa e a licença -- sem precisar de um segundo estado: abrir uma fecha a
 * outra porque é a mesma vaga. Era isso que os `editingCompany = null` espalhados pelos
 * `editLicense` e companhia faziam à mão, e bastava esquecer um para os dois abrirem juntos.
 *
 * O id vazio é o rascunho: a linha que ainda não existe.
 *
 * @param {() => void} render o que repintar quando a vaga muda
 */
export function inlineEditor(render) {
    let open = null;

    return {
        /**
         * Abre a linha `id` do tipo `kind`.
         *
         * Sem `id` não faz nada, em vez de abrir o rascunho. Um botão de editar sem
         * `data-id` -- um render parcial, um template novo -- abria a linha de criar em
         * branco no topo da lista, e gravá-la criava um registo em vez de editar aquele em
         * que se carregou. Quem quer o rascunho pede-o pelo nome, com o `draft()`.
         *
         * O `extra` vem primeiro no espalhamento: um `extra` que trouxesse `id` ou `kind`
         * substituía a identidade já normalizada, e um id numérico deixava de casar com o
         * `String()` do `at()` -- a linha não abria e não havia erro nenhum.
         */
        edit(kind, id, extra = {}) {
            if (id === null || id === undefined || String(id) === "") return;
            open = { ...extra, kind, id: String(id) };
            render();
        },

        /** Abre a linha que ainda não existe. É a vaga com o id vazio. */
        draft(kind, extra = {}) {
            open = { ...extra, kind, id: "" };
            render();
        },

        cancel() {
            open = null;
            render();
        },

        /** Fecha sem repintar, para quem vai recarregar e repintar logo a seguir. */
        reset() {
            open = null;
        },

        /** A linha `id` do tipo `kind` está aberta? Sem `id`, pergunta pelo rascunho. */
        at(kind, id = "") {
            return open !== null && open.kind === kind && open.id === String(id ?? "");
        },

        /** O que está aberto, para quem precisa do `extra`. Uma cópia: a vaga é desta casa. */
        get open() {
            return open === null ? null : { ...open };
        },
    };
}

/**
 * O invólucro aberto a que este botão pertence, e os seus campos.
 *
 * Devolve `null` quando o botão não está dentro de um editor deste tipo -- que não acontece
 * com o HTML que estes módulos desenham, mas um clique delegado apanha o que lá estiver.
 */
export function editorOf(button, kind) {
    const el = button.closest(`[data-editor="${kind}"]`);
    if (!el) return null;

    const field = (name) => el.querySelector(`[data-field="${name}"]`);

    return {
        el,
        id: el.dataset.id || "",
        field,
        /** O valor de um campo de texto, já aparado. */
        value: (name) => field(name).value.trim(),
    };
}

/** O cursor cai no primeiro campo do editor aberto, e não no princípio da lista. */
export function focusEditor(root) {
    root.querySelector("[data-editor] input, [data-editor] select")?.focus();
}
