import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { radarCheckMessage } =
    await import("../../src/Dashboard/dashboard/settings/radar-credentials.js");

/**
 * Autenticar não prova que a conta é desta licença: o que se diz a quem testa a ligação é
 * quantos destes radares a conta conhece.
 */

test("todos respondem, e diz-se que ligou", () => {
    const message = radarCheckMessage({ radars: 21, responding: 21, error: null });

    assert.equal(message.tone, "success");
    assert.match(message.text, /21 radares respondem nesta conta/);
});

test("nenhum responde: a conta autentica mas é de outra licença", () => {
    const message = radarCheckMessage({ radars: 21, responding: 0, error: null });

    assert.equal(message.tone, "danger");
    assert.match(message.text, /nenhum/i);
});

test("uns sim e outros não fica como aviso, não como sucesso", () => {
    const message = radarCheckMessage({ radars: 21, responding: 14, error: null });

    assert.equal(message.tone, "warning");
    assert.match(message.text, /14 de 21/);
});

test("um radar só não leva plural", () => {
    const message = radarCheckMessage({ radars: 1, responding: 1, error: null });

    assert.match(message.text, /1 radar responde\b/);
});

test("sem radares não se inventa um «ligou»", () => {
    const message = radarCheckMessage({ radars: 0, responding: 0, error: null });

    assert.equal(message.tone, "secondary");
    assert.doesNotMatch(message.text, /ligou/i);
});

test("o login que falha diz a causa uma vez", () => {
    const message = radarCheckMessage({
        radars: 21,
        responding: 0,
        error: "Qinglanst login failed",
    });

    assert.equal(message.tone, "danger");
    assert.match(message.text, /Qinglanst login failed/);
});
