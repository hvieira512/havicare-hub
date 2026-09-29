import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { paginationControls } from "../../src/Dashboard/dashboard/components/pagination.js";

/**
 * O paginador num ecrã estreito.
 *
 * Os botões de página medem 24×31px, e catorze deles numa página são catorze alvos abaixo do
 * mínimo de 44. Num telemóvel ficam as duas setas, que aí têm tamanho de dedo; os números
 * voltam a partir de `md`, onde há rato e largura para eles.
 */
const controls = (page, totalPages) =>
    parseFragment(paginationControls({
        pagination: { page, total_pages: totalPages },
        actionPrefix: "devices",
    }));

const items = (root) => [...root.querySelectorAll("li")];

test("as setas vêem-se em qualquer largura", () => {
    const [primeiro, ...resto] = items(controls(3, 14));
    const ultimo = resto.at(-1);

    for (const seta of [primeiro, ultimo]) {
        assert.equal(
            seta.className.includes("d-none"),
            false,
            `a seta não se esconde: ${seta.className}`,
        );
    }
});

test("os números só aparecem a partir de md", () => {
    const numeros = items(controls(3, 14)).slice(1, -1);

    assert.equal(numeros.length > 0, true, "esperavam-se lugares de página");
    for (const lugar of numeros) {
        assert.match(
            lugar.className,
            /\bd-none\b.*\bd-md-block\b|\bd-none\b.*\bd-md-flex\b/,
            `o lugar «${lugar.textContent.trim()}» tem de se esconder abaixo de md: ${lugar.className}`,
        );
    }
});

test("os botões levam a classe do alvo de toque", () => {
    const botoes = [...controls(3, 14).querySelectorAll("button")];

    assert.equal(botoes.length > 0, true);
    for (const botao of botoes) {
        assert.match(
            botao.className,
            /\bpage-link-touch\b/,
            `o botão «${botao.getAttribute("aria-label") || botao.textContent.trim()}» precisa do alvo de 44px`,
        );
    }
});
