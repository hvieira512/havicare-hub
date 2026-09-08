import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { installErrorReporting } from "../../src/Dashboard/dashboard/observability.js";

test("um erro por apanhar é reportado", () => {
    const seen = [];
    installErrorReporting((kind, detail) => seen.push([kind, detail]));

    window.dispatchEvent(Object.assign(new Event("error"), { error: new Error("rebentou") }));

    assert.equal(seen.at(-1)[0], "uncaught");
    assert.equal(seen.at(-1)[1].message, "rebentou");
});

test("uma promessa rejeitada é reportada", () => {
    const seen = [];
    installErrorReporting((kind, detail) => seen.push([kind, detail]));

    window.dispatchEvent(Object.assign(new Event("unhandledrejection"), { reason: "sem catch" }));

    assert.equal(seen.at(-1)[0], "unhandledrejection");
    assert.equal(seen.at(-1)[1], "sem catch");
});
