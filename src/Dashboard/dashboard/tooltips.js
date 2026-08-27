/**
 * As tooltips do Bootstrap são por adesão: um contentor que redesenha a marcação tem de
 * voltar a entregar-lhe os elementos.
 */

/**
 * Liga uma tooltip a cada elemento que a pediu dentro de um contentor.
 *
 * A animação está desligada de propósito. Um contentor que redesenha destrói estas
 * instâncias, e uma tooltip animada agenda o fim do `hide` na transição de fade -- o
 * `dispose()` não cancela esse callback, que depois corre contra uma instância nula e
 * estoura. Esconder de forma síncrona não deixa nada pendente.
 */
export function refreshTooltips(root) {
    const bootstrap = window.bootstrap;
    if (!bootstrap?.Tooltip || !root) return;

    root.querySelectorAll("[data-bs-toggle=\"tooltip\"]").forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element, { animation: false });
    });
}

/** Desliga as tooltips antes de a marcação do contentor ser substituída. */
export function disposeTooltips(root) {
    const bootstrap = window.bootstrap;
    if (!bootstrap?.Tooltip || !root) return;

    root.querySelectorAll("[data-bs-toggle=\"tooltip\"]").forEach((element) => {
        bootstrap.Tooltip.getInstance(element)?.dispose();
    });
}
