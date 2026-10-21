import test from "node:test";
import assert from "node:assert/strict";

import {
    onlineBadge as buildOnlineBadge,
    stateBadge as buildStateBadge,
} from "../../src/Dashboard/dashboard/components/state-badge.js";
import { asText } from "./support/dom.js";

const stateBadge = asText(buildStateBadge);
const onlineBadge = asText(buildOnlineBadge);

/** Um tom que não existe no Bootstrap não pode gerar uma classe que não existe. */
test("an unknown tone produces no class of its own", () => {
    assert.doesNotMatch(stateBadge("Estado", "roxo"), /roxo/);
});

test("the text always states the state, because colour cannot be the only cue", () => {
    assert.match(stateBadge("Perigo", "danger"), />Perigo</);
    assert.match(onlineBadge(true), />Ligado</);
    assert.match(onlineBadge(false), />Desligado</);
});

/** As duas marcas são decoração: o estado lê-se no rótulo, e não na cor nem na forma. */
test("neither the dot nor the icon is announced by screen readers", () => {
    assert.match(stateBadge("Ativo", "success"), /state-badge-dot[^"]*" aria-hidden="true"/);
    assert.match(stateBadge("Ativo", "success", { icon: "fa-check" }), /<i [^>]*aria-hidden="true"/);
});
