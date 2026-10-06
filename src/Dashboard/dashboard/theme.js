import { loadTextStorage, saveTextStorage, THEME_STORAGE_KEY } from "./storage.js";

/**
 * O tema claro/escuro: o `data-bs-theme` no `<html>` troca os tokens do Bootstrap. Sem
 * preferência guardada segue-se a do sistema.
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

/** O tema que está no ecrã agora, para quem desenha fora do CSS e não o herda. */
export function isDarkTheme() {
    return document.documentElement.getAttribute("data-bs-theme") === DARK;
}

/** Há mais do que um: a barra de navegação tem o seu, e a entrada tem o dela. */
const themeButtons = () => [...document.querySelectorAll("[data-theme-toggle]")];

export function applyTheme(theme) {
    const resolved = theme === DARK ? DARK : LIGHT;
    document.documentElement.setAttribute("data-bs-theme", resolved);

    // O SweetAlert veste-se pelo seu atributo, no `<body>` para ganhar à folha dele no `:root`; o
    // sufixo é explícito porque o `bootstrap-5` sozinho segue o sistema operativo.
    document.body?.setAttribute("data-swal2-theme", `bootstrap-5-${resolved}`);

    // O ícone diz para onde se vai, não onde se está. O `fa-fw` mantém a largura ao trocar.
    const goingToDark = resolved === LIGHT;
    const label = goingToDark ? "Mudar para o tema escuro" : "Mudar para o tema claro";

    // Os dois acompanham o tema, senão o que estivesse escondido voltava com o ícone errado.
    themeButtons().forEach((button) => {
        const glyph = button.querySelector("i");
        if (glyph) {
            glyph.classList.toggle("fa-moon", goingToDark);
            glyph.classList.toggle("fa-sun", !goingToDark);
        }
        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);
        button.setAttribute("aria-pressed", resolved === DARK ? "true" : "false");
    });

    return resolved;
}

export function initializeTheme() {
    applyTheme(preferredTheme());

    themeButtons().forEach((button) => button.addEventListener("click", () => {
        const next = isDarkTheme() ? LIGHT : DARK;
        saveTextStorage(THEME_STORAGE_KEY, next);
        applyTheme(next);
    }));
}
