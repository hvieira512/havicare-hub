import {JSDOM} from "jsdom";

/**
 * Os leitores de configuração percorrem nós de DOM a sério, e por isso testá-los precisa de
 * um documento. Tudo aqui é por chamada: não se instala `window` global nenhum, o que evita
 * que estes testes vazem estado uns para os outros.
 */
export function parseFragment(html) {
    return new JSDOM(`<!doctype html><body><div id="root">${html}</div></body>`)
        .window.document.getElementById("root");
}

/**
 * Constrói o elemento que o `readConfigPayload` espera: uma secção com `data-config-input` e
 * os campos desenhados lá dentro, exactamente como o `renderConfigSection` a monta.
 */
export function configSection(renderConfigInputs, entry, desired = {}, meta = {}) {
    const input = entry.input || "json";
    const html = renderConfigInputs(entry, desired, meta);

    // O `data-config-protocol` conta: alguns leitores ramificam nele, e uma secção sem ele
    // exercita o caminho errado em silêncio.
    return parseFragment(
        `<section data-config-input="${input}" data-config-protocol="${meta.protocol ?? ""}" data-config-limit="${entry.limit ?? ""}">${html}</section>`
    ).firstElementChild;
}
