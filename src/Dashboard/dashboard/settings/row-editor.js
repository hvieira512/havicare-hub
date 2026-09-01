/**
 * A edição em linha das listagens das definições: a vaga do que está aberto, a escolha entre
 * a linha de ver e a de editar, a leitura dos `data-field` e o foco depois de repintar.
 *
 * O que varia entre listagens é o que cada uma desenha e o que grava.
 */

/**
 * A linha aberta para edição, numa listagem onde só pode estar uma. O `kind` deixa uma
 * listagem ter vários tipos de linha editável na mesma vaga -- abrir uma fecha a outra.
 *
 * O id vazio é o rascunho: a linha que ainda não existe.
 *
 * @param {() => void} render o que repintar quando a vaga muda
 */
export function inlineEditor(render) {
    let open = null;

    return {
        /**
         * Abre a linha `id` do tipo `kind`. Sem `id` não faz nada em vez de abrir o
         * rascunho, que se pede pelo nome com o `draft()`.
         *
         * O `extra` vem primeiro no espalhamento, senão podia substituir a identidade já
         * normalizada e a linha deixava de abrir sem erro nenhum.
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
