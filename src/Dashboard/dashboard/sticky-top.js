/** A altura do que está colado ao topo, medida, porque a barra quebra num ecrã estreito. */
export function trackStickyTop({ navbar, tabs = [] }) {
    // As duas réguas nunca estão à vista ao mesmo tempo: a escondida mede zero.
    const strips = [tabs].flat().filter(Boolean);
    const targets = [navbar, ...strips].filter(Boolean);
    if (targets.length === 0) return;

    const height = (element) => element?.getBoundingClientRect().height || 0;

    const write = () => {
        const root = document.documentElement.style;
        const navbarHeight = height(navbar);
        const stripsHeight = strips.reduce((total, strip) => total + height(strip), 0);
        root.setProperty("--navbar-height", `${navbarHeight}px`);
        root.setProperty("--sticky-top", `${navbarHeight + stripsHeight}px`);
    };

    write();
    const observer = new ResizeObserver(write);
    for (const target of targets) observer.observe(target);
}
