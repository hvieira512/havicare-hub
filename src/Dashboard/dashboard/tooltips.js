/** Liga uma tooltip a cada elemento que a pediu; sem animação, que o `dispose()` não cancela o fade. */
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
