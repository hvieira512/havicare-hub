/** Os elementos que pediram tooltip; sem o Bootstrap carregado, nenhum. */
const tooltipTargets = (root) =>
    (window.bootstrap?.Tooltip && root ? root.querySelectorAll("[data-bs-toggle=\"tooltip\"]") : []);

/** Liga uma tooltip a cada elemento que a pediu; sem animação, que o `dispose()` não cancela o fade. */
export function refreshTooltips(root) {
    for (const element of tooltipTargets(root)) {
        window.bootstrap.Tooltip.getOrCreateInstance(element, { animation: false });
    }
}

/** Desliga as tooltips antes de a marcação do contentor ser substituída. */
export function disposeTooltips(root) {
    for (const element of tooltipTargets(root)) {
        window.bootstrap.Tooltip.getInstance(element)?.dispose();
    }
}
