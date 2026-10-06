import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { JSDOM } from "jsdom";

/**
 * Instala um DOM antes de qualquer módulo do dashboard ser importado: alguns tocam em `window`
 * ao carregar, e os módulos ES avaliam as dependências por ordem de import.
 */
const dom = new JSDOM("<!doctype html><body></body>", { url: "http://localhost/" });

// O descritor dos tipos que o `index.php` serve em produção, lido do mesmo ficheiro que o PHP
// lê para não haver cópia que divirja.
const deviceTypesIsland = dom.window.document.createElement("script");
deviceTypesIsland.type = "application/json";
deviceTypesIsland.id = "hub-device-types";
deviceTypesIsland.textContent = readFileSync(
    fileURLToPath(new URL("../../../config/device-types.json", import.meta.url)),
    "utf8",
);
dom.window.document.body.appendChild(deviceTypesIsland);

/**
 * O `matchMedia`, que o jsdom não instala, conduzível por quem testa: sem ele o `theme.js` cai
 * sempre no claro, e o ramo do sistema escuro fica por testar.
 */
let prefersDark = false;

export function setPrefersDark(value) {
    prefersDark = value === true;
}

dom.window.matchMedia = (query) => ({
    media: String(query),
    matches: String(query).includes("prefers-color-scheme: dark") && prefersDark,
    addEventListener() {},
    removeEventListener() {},
});

// O node define alguns destes como só-leitura no `globalThis`, e por isso a atribuição passa
// pelo `defineProperty` em vez de ser directa.
for (const name of [
    "window",
    "document",
    "navigator",
    "localStorage",
    "sessionStorage",
    "HTMLElement",
    "Event",
    "CustomEvent",
    "CSS",
]) {
    Object.defineProperty(globalThis, name, {
        value: name === "window" ? dom.window : dom.window[name],
        configurable: true,
        writable: true,
    });
}

export { dom };
