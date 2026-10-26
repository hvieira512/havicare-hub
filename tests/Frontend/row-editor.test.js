import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import {
    editorOf,
    focusEditor,
    inlineEditor,
} from "../../src/Dashboard/dashboard/settings/row-editor.js";
import { whileBusy } from "../../src/Dashboard/dashboard/dom.js";

/**
 * A vaga única do editor em linha, partilhada pelas listagens de empresa, licença e utilizador
 * da API.
 */

test("opening a row closes the open one, even of another kind", () => {
    let renders = 0;
    const editor = inlineEditor(() => renders++);

    editor.edit("company", 7);
    assert.equal(editor.at("company", 7), true);

    editor.edit("license", 3);
    assert.equal(editor.at("company", 7), false);
    assert.equal(editor.at("license", 3), true);
    assert.equal(renders, 2);
});

test("the id compares as text, whether number or string", () => {
    const editor = inlineEditor(() => {});

    editor.edit("license", 1001);
    assert.equal(editor.at("license", "1001"), true);
    assert.equal(editor.at("license", 1001), true);
    assert.equal(editor.at("license", 100), false);
});

test("the draft is the row without id, and is not confused with another kind's empty-id row", () => {
    const editor = inlineEditor(() => {});

    editor.draft("apiUser");
    assert.equal(editor.at("apiUser"), true, "o rascunho está aberto");
    assert.equal(editor.at("apiUser", 5), false, "e não é a linha 5");
    assert.equal(editor.at("company"), false, "nem o rascunho de outro tipo");
});

test("the extra travels with the open row", () => {
    const editor = inlineEditor(() => {});

    // Uma licença nova nasce dentro da empresa em que se carregou no `+`.
    editor.draft("license", { companyId: "4" });
    assert.equal(editor.at("license"), true);
    assert.equal(editor.open.companyId, "4");
});

/** Um editar sem `data-id` gravaria um registo novo; o rascunho pede-se pelo nome. */
test("edit without id opens nothing and does not fall into the draft", () => {
    let renders = 0;
    const editor = inlineEditor(() => renders++);

    for (const missing of [undefined, null, ""]) {
        editor.edit("apiUser", missing);
        assert.equal(editor.open, null, `edit com ${String(missing)} não devia abrir nada`);
    }
    assert.equal(renders, 0, "e não devia sequer repintar");

    editor.draft("apiUser");
    assert.equal(editor.at("apiUser"), true, "o rascunho pedido pelo nome abre");
});

test("an extra with id or kind does not replace the slot's identity", () => {
    const editor = inlineEditor(() => {});

    // O `extra` já carrega contexto da linha, e por isso passar-lhe um `id` é engano fácil.
    editor.edit("license", 7, { id: 99, kind: "company", companyId: "4" });

    assert.equal(editor.at("license", 7), true, "continua a ser a licença 7");
    assert.equal(editor.at("company", 99), false);
    assert.equal(editor.open.companyId, "4", "o resto do extra viaja na mesma");
});

test("`open` is a copy: changing it does not change the slot", () => {
    const editor = inlineEditor(() => {});
    editor.edit("company", 1);

    editor.open.id = "99";

    assert.equal(editor.at("company", 1), true);
});

test("cancel redraws and reset does not", () => {
    let renders = 0;
    const editor = inlineEditor(() => renders++);

    editor.edit("company", 1);
    assert.equal(renders, 1);

    editor.cancel();
    assert.equal(editor.open, null);
    assert.equal(renders, 2);

    editor.edit("company", 1);
    // O `reset` é para quem vai recarregar e repintar a seguir: repintar aqui seria pintar
    // duas vezes, e a segunda por cima de dados velhos.
    editor.reset();
    assert.equal(editor.open, null);
    assert.equal(renders, 3);
});

test("editorOf reads the id and fields of the wrapper the button belongs to", () => {
    const root = parseFragment(`
        <div data-editor="company" data-id="12">
            <input data-field="name" value="  hitcare  ">
            <button id="save">Guardar</button>
        </div>`);
    const row = editorOf(root.querySelector("#save"), "company");

    assert.equal(row.id, "12");
    assert.equal(row.value("name"), "hitcare", "o valor vem aparado");
    assert.equal(row.field("name").tagName, "INPUT");
});

test("editorOf returns null when the button is not in an editor of this kind", () => {
    const root = parseFragment(`
        <div data-editor="license" data-id="3"><button id="save">Guardar</button></div>
        <button id="outside">Outro</button>`);

    assert.equal(editorOf(root.querySelector("#save"), "company"), null);
    assert.equal(editorOf(root.querySelector("#outside"), "license"), null);
});

test("the draft has no id, and editorOf returns an empty string, not undefined", () => {
    const root = parseFragment(`
        <div data-editor="apiUser" data-id="">
            <input data-field="username" value="novo">
            <button id="save">Guardar</button>
        </div>`);

    assert.equal(editorOf(root.querySelector("#save"), "apiUser").id, "");
});

test("focus lands on the editor's first field, not the list's first row", () => {
    const root = parseFragment(`
        <div class="row"><input id="other" value="view row"></div>
        <div data-editor="company" data-id="1">
            <input id="first" data-field="name">
            <select id="second" data-field="role"></select>
        </div>`);

    focusEditor(root);

    assert.equal(root.ownerDocument.activeElement.id, "first");
});

/** Enquanto o pedido viaja, um segundo clique em «Guardar» criaria um registo igual. */
function deferred() {
    let settle;
    const promise = new Promise((resolve) => {
        settle = resolve;
    });

    return { promise, settle };
}

test("the second click does not run until the first finishes", async () => {
    const button = document.createElement("button");
    const pending = deferred();
    let runs = 0;

    const count = (result) => {
        runs += 1;
        return result;
    };

    const first = whileBusy(button, () => count(pending.promise));
    const second = whileBusy(button, () => count(Promise.resolve()));

    assert.equal(runs, 1);
    assert.equal(button.disabled, true);

    pending.settle();
    await Promise.all([first, second]);

    assert.equal(runs, 1);
    assert.equal(button.disabled, false, "o botão volta a aceitar cliques");
});

test("a failure does not leave the button disabled forever", async () => {
    const button = document.createElement("button");

    await whileBusy(button, () => Promise.reject(new Error("falhou"))).catch(() => {});

    assert.equal(button.disabled, false);
});

test("the busy label shows while working and the original returns after", async () => {
    const button = document.createElement("button");
    button.innerHTML = "Guardar";
    const pending = deferred();

    const run = whileBusy(button, () => pending.promise, "A guardar…");
    assert.equal(button.innerHTML, "A guardar…");

    pending.settle();
    await run;
    assert.equal(button.innerHTML, "Guardar");
});
