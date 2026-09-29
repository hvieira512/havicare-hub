/**
 * O que está colado ao topo, em pixéis, para quem cola por baixo. Mede-se em vez de se
 * fixar: a barra de navegação quebra em duas linhas num ecrã estreito, e a régua de
 * separadores só existe enquanto os dois painéis da atividade não cabem lado a lado.
 */
export function trackStickyTop({ navbar, tabs }) {
    const targets = [navbar, tabs].filter(Boolean);
    if (targets.length === 0) return;

    const height = (element) => element?.getBoundingClientRect().height || 0;

    const write = () => {
        const root = document.documentElement.style;
        const navbarHeight = height(navbar);
        root.setProperty("--navbar-height", `${navbarHeight}px`);
        root.setProperty("--sticky-top", `${navbarHeight + height(tabs)}px`);
    };

    write();
    const observer = new ResizeObserver(write);
    for (const target of targets) observer.observe(target);
}
