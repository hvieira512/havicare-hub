import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * A resposta das três descobertas de parâmetros.
 *
 * São três botões no modal, e nada no ecrã sabia desenhar o que eles trazem: caíam no
 * renderizador genérico, que passava a lista de TAGs por `String()`.
 */
const ANSWER = { count: 3, tags: ["0x1001", "0x1002", "0x8103"] };

for (const type of ["supported_configuration", "supported_status", "supported_control"]) {
    test(`${type}: o valor é a contagem`, () => {
        assert.match(uplinkCardContent(type, ANSWER).value, /3/);
    });

    test(`${type}: as TAGs aparecem em hexadecimal`, () => {
        const { details } = uplinkCardContent(type, ANSWER);

        assert.match(details, /0x1001/);
        assert.match(details, /0x8103/);
        assert.doesNotMatch(details, /\[object|,0x/);
    });
}

/** Uma resposta vazia é uma resposta: o aparelho disse que não serve nada dessa família. */
test("nenhuma TAG diz que não serve nenhuma", () => {
    const { value } = uplinkCardContent("supported_control", { count: 0, tags: [] });

    assert.match(value, /[Nn]enhum/);
});

/** Com muitas, a linha não pode crescer sem fim: o resto fica na gaveta. */
test("uma resposta longa resume-se na linha e guarda o resto", () => {
    const many = Array.from({ length: 43 }, (_unused, index) => `0x${(0x8100 + index).toString(16)}`);
    const { details, detailsTitle } = uplinkCardContent("supported_status", { count: 43, tags: many });

    assert.ok(details.length < detailsTitle.length, "a linha devia ser um resumo do que a gaveta traz");
    assert.match(detailsTitle, /0x812a/);
});
