import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { installErrorReporting } from "../../src/Dashboard/dashboard/observability.js";

const seen = [];
installErrorReporting();

beforeEach((context) => {
    seen.length = 0;
    context.mock.method(console, "error", (...args) => seen.push(args));
});

test("um erro por apanhar é reportado", () => {
    window.dispatchEvent(Object.assign(new Event("error"), { error: new Error("rebentou") }));

    assert.equal(seen.at(-1)[0], "[hub] uncaught");
    assert.equal(seen.at(-1)[1].message, "rebentou");
});

test("uma promessa rejeitada é reportada", () => {
    window.dispatchEvent(Object.assign(new Event("unhandledrejection"), { reason: "sem catch" }));

    assert.equal(seen.at(-1)[0], "[hub] unhandledrejection");
    assert.equal(seen.at(-1)[1], "sem catch");
});
