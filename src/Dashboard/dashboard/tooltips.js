/**
 * As tooltips do Bootstrap são por adesão: um contentor que redesenha a marcação tem de
 * voltar a entregar-lhe os elementos.
 */

/**
 * Liga uma tooltip a cada elemento que a pediu. Sem animação: o `dispose()` não cancela o fim
 * do `hide` agendado no fade, que depois estoura contra uma instância nula.
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
