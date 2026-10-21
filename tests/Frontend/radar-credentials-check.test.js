import test from "node:test";
import assert from "node:assert/strict";

const { radarCheckMessage } =
    await import("../../src/Dashboard/dashboard/settings/radar-credentials.js");

/**
 * Autenticar não prova que a conta é desta licença: o que se diz a quem testa a ligação é
 * quantos destes radares a conta conhece.
 */

test("all respond, and it says it connected", () => {
    const message = radarCheckMessage({ radars: 21, responding: 21, error: null });

    assert.equal(message.tone, "success");
    assert.match(message.text, /21 radares respondem nesta conta/);
});

test("none respond: the account authenticates but belongs to another licence", () => {
    const message = radarCheckMessage({ radars: 21, responding: 0, error: null });

    assert.equal(message.tone, "danger");
    assert.match(message.text, /nenhum/i);
});

test("some yes and some no is a warning, not a success", () => {
    const message = radarCheckMessage({ radars: 21, responding: 14, error: null });

    assert.equal(message.tone, "warning");
    assert.match(message.text, /14 de 21/);
});

test("a single radar takes no plural", () => {
    const message = radarCheckMessage({ radars: 1, responding: 1, error: null });

    assert.match(message.text, /1 radar responde\b/);
});

test("without radars no «ligou» is made up", () => {
    const message = radarCheckMessage({ radars: 0, responding: 0, error: null });

    assert.equal(message.tone, "secondary");
    assert.doesNotMatch(message.text, /ligou/i);
});

test("a failed login states the cause once", () => {
    const message = radarCheckMessage({
        radars: 21,
        responding: 0,
        error: "Qinglanst login failed",
    });

    assert.equal(message.tone, "danger");
    assert.match(message.text, /Qinglanst login failed/);
});
