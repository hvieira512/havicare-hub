/** Põe o `data-bs-theme` antes da primeira pintura; a chave é a do `core/storage.js`, escrita à mão. */
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
