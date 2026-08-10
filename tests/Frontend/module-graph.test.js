import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import {fileURLToPath} from "node:url";

/**
 * Guards against a broken ES module graph.
 *
 * Linking resolves every import across the whole graph before any code runs,
 * so a name that a module imports but no module exports takes the entire
 * dashboard down with a blank page -- no script executes at all. `node --check`
 * cannot catch it because each file is individually valid syntax.
 *
 * The entry point is main.js because that is what index.php loads. Checking
 * anything else leaves the modules closest to the entry unguarded, which is
 * where a blank page hurts most.
 *
 * The modules are browser code, so evaluating them in node fails on globals
 * like `window`. That is expected and ignored: only a link-time SyntaxError
 * means a genuinely broken import.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const ENTRY = path.join(here, "../../src/Dashboard/main.js");
const MODULE_ROOT = path.join(here, "../../src/Dashboard/dashboard");

test("module graph links: main.js", async () => {
    try {
        await import(ENTRY);
    } catch (error) {
        assert.notEqual(
            error.constructor.name,
            "SyntaxError",
            `broken import in the main.js graph -- ${error.message}`,
        );
    }
});

const listModules = (dir) =>
    fs.readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return listModules(full);
        return entry.name.endsWith(".js") ? [full] : [];
    });

const reachableFrom = (entry, seen = new Set()) => {
    if (seen.has(entry) || !fs.existsSync(entry)) return seen;
    seen.add(entry);
    const source = fs.readFileSync(entry, "utf8");
    for (const [, specifier] of source.matchAll(/from\s+["']([^"']+)["']/g)) {
        if (specifier.startsWith(".")) {
            reachableFrom(path.resolve(path.dirname(entry), specifier), seen);
        }
    }
    return seen;
};

test("every dashboard module is reachable from the entry point", () => {
    const reachable = reachableFrom(ENTRY);
    const orphans = listModules(MODULE_ROOT).filter((file) => !reachable.has(file));

    // An orphan is either dead code or a module the link check above never
    // sees -- both worth knowing about the moment it appears.
    assert.deepEqual(
        orphans.map((file) => path.relative(MODULE_ROOT, file)),
        [],
    );
});
