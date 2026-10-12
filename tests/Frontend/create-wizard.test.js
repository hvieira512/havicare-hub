import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

/** A transição é da linguagem de selecção partilhada, e por isso a guarda também é de lá. */
test("os cards do assistente respeitam quem pediu menos movimento", () => {
    const css = readFileSync(
        new URL("../../src/Dashboard/assets/css/shell.css", import.meta.url),
        "utf8",
    );

    const quiet = css
        .split("@media (prefers-reduced-motion: reduce)")
        .slice(1)
        .find((block) => block.includes(".wizard-card"));

    assert.ok(quiet, "nenhum bloco de movimento reduzido nomeia o cartão do assistente");
    assert.match(quiet, /transition: none/);
});
