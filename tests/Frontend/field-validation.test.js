import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const {bindInvalidClearing, clearInvalid, markInvalid} = await import(
    "../../src/Dashboard/dashboard/validation.js"
);

/**
 * A validação de um formulário escreve-se no campo e não num diálogo. O que se garante aqui
 * é o que faz a diferença entre isso e o `alert()` que substituiu: a mensagem sai quando o
 * utilizador começa a corrigir, e o foco vai para o primeiro problema e não para o último.
 */
function form(html) {
    document.body.innerHTML = `<form id="f">${html}</form>`;
    return document.getElementById("f");
}

const feedbackAfter = (field) => field.nextElementSibling;

test("marcar um campo escreve a mensagem ao lado dele", () => {
    const f = form('<input id="a">');
    const a = document.getElementById("a");

    markInvalid(a, "O nome é obrigatório");

    assert.equal(a.classList.contains("is-invalid"), true);
    assert.equal(feedbackAfter(a).className, "invalid-feedback");
    assert.equal(feedbackAfter(a).textContent, "O nome é obrigatório");
    // Marcar outra vez reaproveita a mensagem que já lá está.
    markInvalid(a, "Outra coisa");
    assert.equal(f.querySelectorAll(".invalid-feedback").length, 1);
    assert.equal(feedbackAfter(a).textContent, "Outra coisa");
});

test("o foco vai para o primeiro campo marcado e não para o último", () => {
    form('<input id="a"><input id="b">');
    const a = document.getElementById("a");
    const b = document.getElementById("b");

    markInvalid(a, "Falta o primeiro");
    markInvalid(b, "Falta o segundo");

    assert.equal(document.activeElement, a);
});

test("limpar aceita o formulário inteiro ou um campo só", () => {
    const f = form('<input id="a"><input id="b">');
    const a = document.getElementById("a");
    const b = document.getElementById("b");
    markInvalid(a, "x");
    markInvalid(b, "y");

    clearInvalid(a);
    assert.equal(a.classList.contains("is-invalid"), false);
    assert.equal(b.classList.contains("is-invalid"), true);

    clearInvalid(f);
    assert.equal(f.querySelectorAll(".is-invalid").length, 0);
});

test("escrever no campo marcado limpa-o, e clicar nele não", () => {
    form('<input id="a">');
    const a = document.getElementById("a");
    bindInvalidClearing(document);
    markInvalid(a, "O nome é obrigatório");

    a.dispatchEvent(new window.Event("click", {bubbles: true}));
    assert.equal(a.classList.contains("is-invalid"), true);

    a.dispatchEvent(new window.Event("input", {bubbles: true}));
    assert.equal(a.classList.contains("is-invalid"), false);
});

test("escolher dentro de um grupo de botões limpa a marca do grupo", () => {
    form('<div id="g"><button id="opt" type="button">Opção</button></div>');
    const g = document.getElementById("g");
    bindInvalidClearing(document);
    markInvalid(g, "O fornecedor é obrigatório");

    document
        .getElementById("opt")
        .dispatchEvent(new window.Event("click", {bubbles: true}));

    assert.equal(g.classList.contains("is-invalid"), false);
});
