import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { imeiEmphasis } from "../../src/Dashboard/dashboard/devices/device-card.js";

/** O que se realça é medido contra os vizinhos, e não um número fixo de dígitos. */
test("o prefixo que os vizinhos partilham esbate-se", () => {
    const { prefix, suffix } = imeiEmphasis("594B3CB301AB", [
        "594B3CB31A87",
        "594B3CB3B417",
    ]);

    assert.equal(prefix, "594B3CB3");
    assert.equal(suffix, "01AB");
});

test("sem vizinhos, o identificador fica inteiro", () => {
    const { prefix, suffix } = imeiEmphasis("594B3CB301AB", []);

    assert.equal(prefix, "");
    assert.equal(suffix, "594B3CB301AB");
});

test("vizinhos sem nada em comum não esbatem nada", () => {
    const { prefix, suffix } = imeiEmphasis("594B3CB301AB", ["861265061009822"]);

    assert.equal(prefix, "");
    assert.equal(suffix, "594B3CB301AB");
});

test("o próprio não conta como vizinho de si mesmo", () => {
    const { suffix } = imeiEmphasis("594B3CB301AB", [
        "594B3CB301AB",
        "594B3CB3B417",
    ]);

    assert.equal(suffix, "01AB");
});

test("fica sempre um punhado de dígitos a cheio, mesmo entre quase-gémeos", () => {
    // Estes dois diferem no antepenúltimo dígito; esbater até lá deixava dois a cheio.
    const { prefix, suffix } = imeiEmphasis("861265061009822", ["861265061009830"]);

    assert.equal(suffix.length, 4);
    assert.equal(prefix + suffix, "861265061009822");
});

test("um prefixo curto não vale o realce", () => {
    // Dois dígitos em comum não são um padrão; esbatê-los era ruído.
    const { prefix } = imeiEmphasis("59ABCDEF", ["59123456"]);

    assert.equal(prefix, "");
});
