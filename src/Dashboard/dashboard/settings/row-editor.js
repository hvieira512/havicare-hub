/**
 * A edição em linha das listagens das definições: a vaga do que está aberto, a escolha entre
 * ver e editar, a leitura dos `data-field` e o foco depois de repintar.
 */

/**
 * A linha aberta para edição, numa listagem onde só pode estar uma; o `kind` deixa vários
 * tipos de linha partilharem a vaga. O id vazio é o rascunho.
 *
 * @param {() => void} render o que repintar quando a vaga muda
 */
export function inlineEditor(render) {
    let open = null;

    return {
        /**
         * Abre a linha `id` do tipo `kind`; sem `id` não faz nada, e o rascunho pede-se com o
         * `draft()`. O `extra` vem primeiro no espalhamento para não substituir a identidade.
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
 * O invólucro aberto a que este botão pertence, e os seus campos; `null` fora de um editor
 * deste tipo.
 */
export function editorOf(button, kind) {
    const el = button.closest(`[data-editor="${kind}"]`);
    if (!el) return null;

    const field = (name) => el.querySelector(`[data-field="${name}"]`);

    return {
        el,
        id: el.dataset.id || "",
        field,
        // Um campo que não está desenhado vale vazio: um segredo já guardado mostra-se como
        // guardado e só vira campo quando alguém carrega em «Substituir».
        value: (name) => field(name)?.value.trim() ?? "",
    };
}

/** O cursor cai no primeiro campo do editor aberto, e não no princípio da lista. */
export function focusEditor(root) {
    root.querySelector("[data-editor] input, [data-editor] select")?.focus();
}

/**
 * Corre o que o botão dispara com ele desligado até acabar, para dois cliques seguidos não
 * criarem dois registos.
 */
export async function whileBusy(button, work) {
    if (!button || button.disabled) return;
    button.disabled = true;
    try {
        await work();
    } finally {
        button.disabled = false;
    }
}
