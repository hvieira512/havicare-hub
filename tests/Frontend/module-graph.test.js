import test from "node:test";
import assert from "node:assert/strict";

/**
 * Guards against a broken ES module graph.
 *
 * Linking resolves every import across the whole graph before any code runs,
 * so a name that a module imports but no module exports takes the entire
 * dashboard down with a blank page -- no script executes at all. `node --check`
 * cannot catch it because each file is individually valid syntax.
 *
 * The modules are browser code, so evaluating them in node fails on globals
 * like `window`. That is expected and ignored: only a link-time SyntaxError
 * means a genuinely broken import.
 */
const ENTRY_POINTS = [
    "../../src/Dashboard/dashboard/core/bootstrap.js",
    "../../src/Dashboard/dashboard/devices/list-detail.js",
    "../../src/Dashboard/dashboard/domain.js",
    "../../src/Dashboard/dashboard/renderers.js",
];

for (const entry of ENTRY_POINTS) {
    test(`module graph links: ${entry.split("/").pop()}`, async () => {
        try {
            await import(entry);
        } catch (error) {
            assert.notEqual(
                error.constructor.name,
                "SyntaxError",
                `broken import in the ${entry} graph -- ${error.message}`,
            );
        }
    });
}
