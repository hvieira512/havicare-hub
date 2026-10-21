import assert from "node:assert/strict";
import test from "node:test";

import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

test("no registered renderer is left without a render or a read", () => {
    for (const [name, descriptor] of Object.entries(CONFIG_INPUTS)) {
        assert.equal(typeof descriptor.read, "function", `${name} não sabe ler o que desenha`);

        const renders = typeof descriptor.render === "function" ||
            typeof descriptor.control === "function";
        assert.ok(renders || name === "action", `${name} não desenha nada e não é uma acção`);
    }
});
