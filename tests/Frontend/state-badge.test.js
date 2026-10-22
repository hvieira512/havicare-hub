import test from "node:test";
import assert from "node:assert/strict";

import {
    onlineBadge as buildOnlineBadge,
    presenceBadge as buildPresenceBadge,
    stateBadge as buildStateBadge,
} from "../../src/Dashboard/dashboard/components/state-badge.js";
import { asText } from "./support/dom.js";

const stateBadge = asText(buildStateBadge);
const onlineBadge = asText(buildOnlineBadge);
const presenceBadge = asText(buildPresenceBadge);

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

/** Quem só se liga para medir não está «desligado» fora da medição: está à espera da próxima. */
test("an on-demand device is measuring during its session and never offline after it", () => {
    assert.match(presenceBadge({ presence: "measuring", online: true }), />A medir</);
    assert.doesNotMatch(presenceBadge({ presence: "idle", online: false }), /Desligado/);
});

test("a device that stays connected is online or offline", () => {
    assert.match(presenceBadge({ presence: "online", online: true }), />Ligado</);
    assert.match(presenceBadge({ presence: "offline", online: false }), />Desligado</);
});
