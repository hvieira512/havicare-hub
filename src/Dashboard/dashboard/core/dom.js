/** Todos os elementos com `id`, por `id`; o `DashboardElementIdsTest` confere-os com a marcação. */
export let els = {};

/** Enche o `els` com os elementos da página, ou com os que um teste lhe der. */
export function cacheElements(found = null) {
    els = found ?? Object.fromEntries([...document.querySelectorAll("[id]")].map((el) => [el.id, el]));
    return els;
}

/** Corre o `fn` a cada `ms` e ao voltar ao separador; escondido, não corre. */
export function whileVisible(fn, ms) {
    const run = () => {
        if (!document.hidden) fn();
    };
    setInterval(run, ms);
    document.addEventListener("visibilitychange", run);
}

const lastHtml = new WeakMap();

/** Escreve a marcação só quando difere da anterior, porque substituir o `innerHTML` tira o foco. */
export function renderInto(container, markup, beforeWrite = null) {
    // Pelo texto: o `html` devolve um `Fragment` novo a cada render.
    markup = String(markup);
    if (!container || lastHtml.get(container) === markup) return false;

    beforeWrite?.(container);
    lastHtml.set(container, markup);
    container.innerHTML = markup;
    return true;
}

/** Corre o trabalho com o botão desligado, para dois cliques não criarem dois registos; com o botão já ocupado devolve `undefined`. */
export async function whileBusy(button, work, busyHtml = null) {
    if (!button || button.disabled) return;
    const idleHtml = button.innerHTML;
    button.disabled = true;
    if (busyHtml !== null) button.innerHTML = busyHtml;
    try {
        return await work();
    } finally {
        button.disabled = false;
        if (busyHtml !== null) button.innerHTML = idleHtml;
    }
}
