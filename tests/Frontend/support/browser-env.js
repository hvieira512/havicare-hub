import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { JSDOM } from "jsdom";

/**
 * Instala um DOM antes de qualquer módulo do dashboard ser importado.
 *
 * Alguns módulos tocam em `window` ao carregar -- o `api/http.js` agenda um refresh de token
 * só por ser importado --, e por isso isto tem de ser avaliado primeiro. Os módulos ES
 * avaliam as dependências por ordem de import, e importar isto acima do módulo em teste basta.
 */
const dom = new JSDOM("<!doctype html><body></body>", { url: "http://localhost/" });

// O descritor dos tipos que o `index.php` serve em produção. Lê-se o mesmo ficheiro que o
// PHP lê, e não uma cópia aqui: uma cópia acabava por divergir daquilo que os testes existem
// para verificar.
dom.window.hubDeviceTypes = JSON.parse(
    readFileSync(
        fileURLToPath(new URL("../../../config/device-types.json", import.meta.url)),
        "utf8",
    ),
);

// O node define alguns destes como só-leitura no `globalThis`, e por isso a atribuição passa
// pelo `defineProperty` em vez de ser directa.
for (const name of [
    "window",
    "document",
    "navigator",
    "localStorage",
    "sessionStorage",
    "HTMLElement",
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
