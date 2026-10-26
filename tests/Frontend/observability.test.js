import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { installErrorReporting } from "../../src/Dashboard/dashboard/core/observability.js";

const seen = [];
installErrorReporting();

beforeEach((context) => {
    seen.length = 0;
    context.mock.method(console, "error", (...args) => seen.push(args));
});

test("an uncaught error is reported", () => {
    window.dispatchEvent(Object.assign(new Event("error"), { error: new Error("rebentou") }));

    assert.equal(seen.at(-1)[0], "[hub] uncaught");
    assert.equal(seen.at(-1)[1].message, "rebentou");
});

test("a rejected promise is reported", () => {
    window.dispatchEvent(Object.assign(new Event("unhandledrejection"), { reason: "sem catch" }));

    assert.equal(seen.at(-1)[0], "[hub] unhandledrejection");
    assert.equal(seen.at(-1)[1], "sem catch");
});
