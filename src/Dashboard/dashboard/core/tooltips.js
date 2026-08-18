/**
 * Bootstrap tooltips are opt-in, so any container whose markup is re-rendered has
 * to hand its elements to Bootstrap again afterwards.
 */

/**
 * Attach a tooltip to every opted-in element inside a container.
 *
 * The animation is deliberately off. A container that re-renders disposes these
 * instances, and an animated tooltip queues its hide completion on the fade
 * transition -- dispose() cannot cancel that callback, so it later runs against a
 * nulled instance and throws. Hiding synchronously leaves nothing pending to race
 * with the next render.
 */
export function refreshTooltips(root) {
    const bootstrap = window.bootstrap;
    if (!bootstrap?.Tooltip || !root) return;

    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element, {animation: false});
    });
}

/** Tear tooltips down before the container's markup is replaced. */
export function disposeTooltips(root) {
    const bootstrap = window.bootstrap;
    if (!bootstrap?.Tooltip || !root) return;

    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getInstance(element)?.dispose();
    });
}
