import {JSDOM} from "jsdom";

/**
 * The configuration readers walk real DOM nodes, so characterising them needs a
 * document. Everything here is per-call: no global window is installed, which
 * keeps these tests from leaking state into each other.
 */
export function parseFragment(html) {
    return new JSDOM(`<!doctype html><body><div id="root">${html}</div></body>`)
        .window.document.getElementById("root");
}

/**
 * Builds the element `readConfigPayload` expects: a section carrying
 * `data-config-input` with the rendered inputs inside, exactly as
 * `renderConfigSection` assembles it.
 */
export function configSection(renderConfigInputs, entry, desired = {}, meta = {}) {
    const input = entry.input || "json";
    const html = renderConfigInputs(entry, desired, meta);

    // data-config-protocol matters: some readers branch on it, so a section
    // without it silently exercises the wrong path.
    return parseFragment(
        `<section data-config-input="${input}" data-config-protocol="${meta.protocol ?? ""}" data-config-limit="${entry.limit ?? ""}">${html}</section>`
    ).firstElementChild;
}
