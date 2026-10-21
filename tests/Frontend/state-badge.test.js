import test from "node:test";
import assert from "node:assert/strict";

import {
    onlineBadge as buildOnlineBadge,
    stateBadge as buildStateBadge,
} from "../../src/Dashboard/dashboard/components/state-badge.js";

// Os construtores devolvem um fragmento de marcação; as assertivas de texto querem texto.
const stateBadge = (...args) => String(buildStateBadge(...args));
const onlineBadge = (...args) => String(buildOnlineBadge(...args));

/** Um tom que não existe no Bootstrap não pode gerar uma classe que não existe. */
test("um tom desconhecido cai no secundário", () => {
    assert.doesNotMatch(stateBadge("Estado", "roxo"), /roxo/);
});

test("o texto diz sempre qual é o estado, porque a cor não pode ser a única leitura", () => {
    assert.match(stateBadge("Perigo", "danger"), />Perigo</);
    assert.match(onlineBadge(true), />Ligado</);
    assert.match(onlineBadge(false), />Desligado</);
});

/** As duas marcas são decoração: o estado lê-se no rótulo, e não na cor nem na forma. */
test("nem o ponto nem o ícone são anunciados pelo leitor de ecrã", () => {
    assert.match(stateBadge("Ativo", "success"), /state-badge-dot[^"]*" aria-hidden="true"/);
    assert.match(stateBadge("Ativo", "success", { icon: "fa-check" }), /<i [^>]*aria-hidden="true"/);
});
