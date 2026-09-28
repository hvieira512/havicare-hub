import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { loadScript } from "../../src/Dashboard/dashboard/load-script.js";

const tagsFor = (src) => document.querySelectorAll(`script[src="${src}"]`);

/**
 * O jsdom não descarrega nada: o `load` e o `error` são disparados aqui à mão. Sempre no
 * último `<script>` daquele `src` -- depois de uma falha há dois, e o primeiro já disparou.
 */
const settle = (src, event) => {
    const tags = tagsFor(src);
    tags[tags.length - 1].dispatchEvent(new window.Event(event));
};

test("duas chamadas ao mesmo script partilham uma carga só", async () => {
    const src = "/assets/vendor/uma-vez/lib.js";

    const first = loadScript(src);
    const second = loadScript(src);

    assert.equal(tagsFor(src).length, 1, "um só <script> no documento");
    assert.equal(first, second, "a segunda chamada devolve a mesma promessa");

    settle(src, "load");
    await first;
});

/**
 * Uma biblioteca que não chegou -- rede em baixo, deploy a meio -- não pode ficar a falhar
 * para sempre: quem voltar a abrir o ecrã tem de conseguir tentar outra vez.
 */
test("um erro liberta a cache e a chamada seguinte tenta de novo", async () => {
    const src = "/assets/vendor/falha/lib.js";

    const first = loadScript(src);
    settle(src, "error");
    await assert.rejects(first, /Não foi possível carregar/);

    const retry = loadScript(src);
    assert.equal(tagsFor(src).length, 2, "a segunda tentativa injeta um <script> novo");

    settle(src, "load");
    await retry;
});

test("scripts diferentes carregam-se em separado", async () => {
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
