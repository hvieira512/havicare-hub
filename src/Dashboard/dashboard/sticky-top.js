/**
 * O que está colado ao topo, em pixéis, para quem cola por baixo. Mede-se, porque a barra quebra
 * num ecrã estreito e a régua só existe enquanto os painéis não cabem lado a lado.
 */
export function trackStickyTop({ navbar, tabs = [] }) {
    // São duas réguas, e nunca as duas ao mesmo tempo: a do telemóvel e a do cartão. A que
    // está escondida mede zero, e somá-las dá sempre a que está no ecrã.
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
