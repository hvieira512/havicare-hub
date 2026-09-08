/**
 * O tema tem de estar posto antes da primeira pintura, senão a página abre clara e escurece à
 * frente de quem está a olhar. Por isso é um script clássico -- sem `defer` e sem ser módulo --
 * e vem no `<head>` antes das folhas de estilo: corre e põe o `data-bs-theme` antes de o CSS
 * sequer carregar. A chave é a mesma do `storage.js`, escrita à mão porque aqui não há módulos.
 */
(function () {
    try {
        const stored = localStorage.getItem("hub-dashboard-theme");
        const dark = stored === "dark"
            || (stored !== "light" && window.matchMedia("(prefers-color-scheme: dark)").matches);
        document.documentElement.setAttribute("data-bs-theme", dark ? "dark" : "light");
    } catch {
        document.documentElement.setAttribute("data-bs-theme", "light");
    }
})();
