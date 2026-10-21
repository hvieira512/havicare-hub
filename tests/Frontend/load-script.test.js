import test from "node:test";
import assert from "node:assert/strict";

import { loadScript } from "../../src/Dashboard/dashboard/load-script.js";

const tagsFor = (src) => document.querySelectorAll(`script[src="${src}"]`);

/** O jsdom não descarrega nada. No último `<script>`: depois de uma falha há dois. */
const settle = (src, event) => {
    const tags = tagsFor(src);
    tags[tags.length - 1].dispatchEvent(new window.Event(event));
};

test("two calls for the same script share one load", async () => {
    const src = "/assets/vendor/uma-vez/lib.js";

    const first = loadScript(src);
    const second = loadScript(src);

    assert.equal(tagsFor(src).length, 1, "um só <script> no documento");
    assert.equal(first, second, "a segunda chamada devolve a mesma promessa");

    settle(src, "load");
    await first;
});

test("an error frees the cache and the next call tries again", async () => {
    const src = "/assets/vendor/falha/lib.js";

    const first = loadScript(src);
    settle(src, "error");
    await assert.rejects(first, /Não foi possível carregar/);

    const retry = loadScript(src);
    assert.equal(tagsFor(src).length, 2, "a segunda tentativa injeta um <script> novo");

    settle(src, "load");
    await retry;
});

test("different scripts load separately", async () => {
    const one = "/assets/vendor/a/a.js";
    const other = "/assets/vendor/b/b.js";

    const first = loadScript(one);
    const second = loadScript(other);

    assert.equal(tagsFor(one).length, 1);
    assert.equal(tagsFor(other).length, 1);

    settle(one, "load");
    settle(other, "load");
    await Promise.all([first, second]);
});
