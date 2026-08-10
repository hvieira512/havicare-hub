import {JSDOM} from "jsdom";

/**
 * Installs a DOM before any dashboard module is imported.
 *
 * Some modules touch `window` at load time -- api/http.js schedules a token
 * refresh as a side effect of being imported -- so this has to be evaluated
 * first. ES modules evaluate dependencies in import order, so importing this
 * above the module under test is enough.
 */
const dom = new JSDOM("<!doctype html><body></body>", {url: "http://localhost/"});

// node defines some of these as getter-only on globalThis, so assign through
// defineProperty rather than plain assignment.
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

export {dom};
