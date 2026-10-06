/**
 * Script clássico no `<head>`, antes das folhas de estilo, para pôr o `data-bs-theme` antes da
 * primeira pintura. A chave é a do `storage.js`, escrita à mão porque aqui não há módulos.
 */
(function () {
    try {
        const stored = localStorage.getItem("hub-dashboard-theme");
        const dark = stored === "dark" ||
            (stored !== "light" && window.matchMedia("(prefers-color-scheme: dark)").matches);
        document.documentElement.setAttribute("data-bs-theme", dark ? "dark" : "light");
    } catch {
        document.documentElement.setAttribute("data-bs-theme", "light");
    }
})();
