import { loadTextStorage, saveTextStorage, THEME_STORAGE_KEY } from "./storage.js";

/**
 * O tema claro/escuro.
 *
 * Quem faz o trabalho é o Bootstrap 5.3: o `data-bs-theme` no `<html>` troca-lhe os tokens
 * todos, e como as folhas deste projecto pedem a cor por `var(--bs-*)` quase tudo vira
 * sozinho. O que é nosso -- o navy da marca, a escala de humidade, a superfície da entrada
 * -- tem o seu par no bloco `[data-bs-theme="dark"]` de cada ficheiro.
 *
 * Sem preferência guardada segue-se a do sistema. Guardada, ganha ela: quem carregou no
 * botão disse o que queria, e não é o sistema operativo que o desdiz.
 */

export const LIGHT = "light";
export const DARK = "dark";

function systemPrefersDark() {
    return window.matchMedia?.("(prefers-color-scheme: dark)").matches === true;
}

export function preferredTheme() {
    const stored = loadTextStorage(THEME_STORAGE_KEY);
    if (stored === LIGHT || stored === DARK) {
        return stored;
    }

    return systemPrefersDark() ? DARK : LIGHT;
}

export function applyTheme(theme) {
    const resolved = theme === DARK ? DARK : LIGHT;
    document.documentElement.setAttribute("data-bs-theme", resolved);

    // O SweetAlert veste-se pelo seu próprio atributo. Vai no `<body>` e não no `<html>`
    // porque a folha do SweetAlert põe os valores base no `:root` e entra depois desta: o
    // que está mais perto do diálogo é que ganha. O sufixo é explícito porque o
    // `bootstrap-5` sozinho segue o sistema operativo, e aqui quem manda é o botão.
    document.body?.setAttribute("data-swal2-theme", `bootstrap-5-${resolved}`);

    const button = document.getElementById("dashboardThemeBtn");
    if (!button) {
        return resolved;
    }

    // O ícone diz para onde se vai, não onde se está: no claro mostra-se a lua porque é a
    // lua que se vai buscar. O `fa-fw` mantém a largura ao trocar -- sem ele o botão mudava
    // de tamanho ao ser carregado, e um controlo não se mexe por ter sido usado.
    const goingToDark = resolved === LIGHT;
    const label = goingToDark ? "Mudar para o tema escuro" : "Mudar para o tema claro";
    const glyph = button.querySelector("i");
    if (glyph) {
        glyph.classList.toggle("fa-moon", goingToDark);
        glyph.classList.toggle("fa-sun", !goingToDark);
    }
    button.setAttribute("aria-label", label);
    button.setAttribute("title", label);
    button.setAttribute("aria-pressed", resolved === DARK ? "true" : "false");

    return resolved;
}

export function initializeTheme() {
    applyTheme(preferredTheme());

    document.getElementById("dashboardThemeBtn")?.addEventListener("click", () => {
        const next = document.documentElement.getAttribute("data-bs-theme") === DARK ? LIGHT : DARK;
        saveTextStorage(THEME_STORAGE_KEY, next);
        applyTheme(next);
    });
}
