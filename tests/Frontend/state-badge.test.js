import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { onlineBadge, stateBadge } from "../../src/Dashboard/dashboard/components/state-badge.js";

/**
 * A pastilha de estado, em classes do Bootstrap. Era CSS da casa a refazer o que o
 * `badge` já traz, e por isso desalinhava-se sempre que caía num contentor novo -- dentro
 * de uma célula do AG Grid herdava a altura da linha e transbordava.
 *
 * O tom é o nome do Bootstrap e não uma classe escrita: quem chama diz "success", e é a
 * pastilha que sabe que isso são um fundo subtil e um texto de ênfase.
 */
test("o tom vira o par de classes do Bootstrap", () => {
    const html = stateBadge("Ativo", "success");

    assert.match(html, /class="[^"]*\bbg-success-subtle\b/);
    assert.match(html, /class="[^"]*\btext-success-emphasis\b/);
    assert.match(html, /class="[^"]*\bbadge\b/);
    assert.match(html, /class="[^"]*\brounded-pill\b/);
});

test("sem tom fica no secundário, e não sem cor nenhuma", () => {
    assert.match(stateBadge("Sem alterações"), /bg-secondary-subtle/);
});

/**
 * O `badge` cru é mais escuro, mais pesado e mais baixo do que a pastilha da plataforma. Os
 * três degraus que faltam existem como utilitários, e por isso não precisam de CSS próprio.
 */
test("a pastilha corrige o peso, a altura de linha e o padding do `badge`", () => {
    const html = stateBadge("Ativo", "success");

    assert.match(html, /class="[^"]*\bfw-semibold\b/);
    assert.match(html, /class="[^"]*\blh-sm\b/);
    assert.match(html, /class="[^"]*\bpx-2\b/);
    assert.doesNotMatch(html, /class="[^"]*\bfw-bold\b/);
});

/**
 * O texto de ênfase do secundário é quase preto, e um estado neutro pintado assim lê-se com
 * o peso de um alarme. Os outros tons já são os da plataforma e ficam como estão.
 */
test("o tom neutro usa a cor suave do corpo, e não a de ênfase", () => {
    const neutro = stateBadge("Desligado");

    assert.match(neutro, /class="[^"]*\btext-body-secondary\b/);
    assert.doesNotMatch(neutro, /text-secondary-emphasis/);
    assert.match(stateBadge("Ativo", "success"), /class="[^"]*\btext-success-emphasis\b/);
});

/** Um tom que não existe no Bootstrap não pode gerar uma classe que não existe. */
test("um tom desconhecido cai no secundário", () => {
    assert.match(stateBadge("Estado", "roxo"), /bg-secondary-subtle/);
    assert.doesNotMatch(stateBadge("Estado", "roxo"), /roxo/);
});

/** A `line-height: 1` do `badge` é o que impede a pastilha de crescer com o contentor. */
test("nada do CSS antigo sobrevive na marcação", () => {
    const html = stateBadge("Ativo", "success");

    assert.doesNotMatch(html, /config-state/);
});

test("o texto diz sempre qual é o estado, porque a cor não pode ser a única leitura", () => {
    assert.match(stateBadge("Perigo", "danger"), />Perigo</);
    assert.match(onlineBadge(true), />Ligado</);
    assert.match(onlineBadge(false), />Desligado</);
});

test("ligado e desligado não são o mesmo tom", () => {
    assert.match(onlineBadge(true), /bg-success-subtle/);
    assert.match(onlineBadge(false), /bg-secondary-subtle/);
});

/** O rótulo é conteúdo, e conteúdo escapa-se. */
test("o rótulo é escapado", () => {
    assert.match(stateBadge("<script>x</script>", "success"), /&lt;script&gt;/);
});

test("uma classe extra junta-se às do Bootstrap em vez de as substituir", () => {
    const html = stateBadge("Ativo", "success", "ms-2");

    assert.match(html, /class="[^"]*\bms-2\b/);
    assert.match(html, /class="[^"]*\bbadge\b/);
});

/**
 * O ponto é o sinal por omissão da plataforma, e quem já chama a pastilha não pediu ícone
 * nenhum. Trocá-lo por um ícone mudava o aspecto de todos os ecrãs que a usam.
 */
test("sem pedido de ícone, a marca continua a ser o ponto", () => {
    const html = stateBadge("Ativo", "success");

    assert.match(html, /class="state-badge-dot rounded-circle d-inline-block"/);
    assert.doesNotMatch(html, /<i /);
});

/** Um ícone substitui o ponto: são duas marcas para o mesmo lugar, e não se acumulam. */
test("um ícone entra no lugar do ponto", () => {
    const html = stateBadge("Administrador", "primary", { icon: "fa-shield-halved" });

    assert.match(html, /<i class="fa-solid fa-shield-halved" aria-hidden="true"><\/i>/);
    assert.doesNotMatch(html, /state-badge-dot/);
});

/** As duas marcas são decoração: o estado lê-se no rótulo, e não na cor nem na forma. */
test("nem o ponto nem o ícone são anunciados pelo leitor de ecrã", () => {
    assert.match(stateBadge("Ativo", "success"), /state-badge-dot[^"]*" aria-hidden="true"/);
    assert.match(stateBadge("Ativo", "success", { icon: "fa-check" }), /<i [^>]*aria-hidden="true"/);
});

/** Pedir ícone não pode custar a classe extra: as duas opções viajam no mesmo objecto. */
test("o ícone e a classe extra cabem no mesmo objecto de opções", () => {
    const html = stateBadge("Cliente", "info", { icon: "fa-building", class: "ms-2" });

    assert.match(html, /class="[^"]*\bms-2\b/);
    assert.match(html, /class="[^"]*\bbg-info-subtle\b/);
    assert.match(html, /<i class="fa-solid fa-building" aria-hidden="true"><\/i>/);
});
