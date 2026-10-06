import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { paginationControls } from "../../src/Dashboard/dashboard/components/pagination.js";

/**
 * Os números de página medem 24×31px, abaixo do mínimo de 44: num telemóvel ficam as duas
 * setas, e os números voltam a partir de `md`.
 */
const controls = (page, totalPages) =>
    parseFragment(paginationControls({
        pagination: { page, total_pages: totalPages },
        actionPrefix: "devices",
    }));

const items = (root) => [...root.querySelectorAll("li")];

test("as setas vêem-se em qualquer largura", () => {
    const [first, ...rest] = items(controls(3, 14));
    const last = rest.at(-1);

    for (const arrow of [first, last]) {
        assert.equal(
            arrow.className.includes("d-none"),
            false,
            `a seta não se esconde: ${arrow.className}`,
        );
    }
});

test("os números só aparecem a partir de md", () => {
    const numbers = items(controls(3, 14)).slice(1, -1);

    assert.equal(numbers.length > 0, true, "esperavam-se lugares de página");
    for (const slot of numbers) {
        assert.match(
            slot.className,
            /\bd-none\b.*\bd-md-block\b|\bd-none\b.*\bd-md-flex\b/,
            `o lugar «${slot.textContent.trim()}» tem de se esconder abaixo de md: ${slot.className}`,
        );
    }
});

test("os botões levam a classe do alvo de toque", () => {
    const buttons = [...controls(3, 14).querySelectorAll("button")];

    assert.equal(buttons.length > 0, true);
    for (const button of buttons) {
        assert.match(
            button.className,
            /\bpage-link-touch\b/,
            `o botão «${button.getAttribute("aria-label") || button.textContent.trim()}» precisa do alvo de 44px`,
        );
    }
});
