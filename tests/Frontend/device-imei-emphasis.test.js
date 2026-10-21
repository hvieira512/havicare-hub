import test from "node:test";
import assert from "node:assert/strict";

import { imeiEmphasis } from "../../src/Dashboard/dashboard/devices/device-card.js";

/** O que se realça é medido contra os vizinhos, e não um número fixo de dígitos. */
test("the prefix shared with neighbours fades", () => {
    const { prefix, suffix } = imeiEmphasis("594B3CB301AB", [
        "594B3CB31A87",
        "594B3CB3B417",
    ]);

    assert.equal(prefix, "594B3CB3");
    assert.equal(suffix, "01AB");
});

test("without neighbours, the identifier stays whole", () => {
    const { prefix, suffix } = imeiEmphasis("594B3CB301AB", []);

    assert.equal(prefix, "");
    assert.equal(suffix, "594B3CB301AB");
});

test("neighbours with nothing in common fade nothing", () => {
    const { prefix, suffix } = imeiEmphasis("594B3CB301AB", ["861265061009822"]);

    assert.equal(prefix, "");
    assert.equal(suffix, "594B3CB301AB");
});

test("a device does not count as its own neighbour", () => {
    const { suffix } = imeiEmphasis("594B3CB301AB", [
        "594B3CB301AB",
        "594B3CB3B417",
    ]);

    assert.equal(suffix, "01AB");
});

test("a handful of digits always stays bold, even between near-twins", () => {
    // Estes dois diferem no antepenúltimo dígito; esbater até lá deixaria dois a cheio.
    const { prefix, suffix } = imeiEmphasis("861265061009822", ["861265061009830"]);

    assert.equal(suffix.length, 4);
    assert.equal(prefix + suffix, "861265061009822");
});

test("a short prefix is not worth highlighting", () => {
    // Dois dígitos em comum não são um padrão; esbatê-los seria ruído.
    const { prefix } = imeiEmphasis("59ABCDEF", ["59123456"]);

    assert.equal(prefix, "");
});
